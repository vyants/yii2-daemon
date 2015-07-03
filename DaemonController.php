<?php

namespace vyants\daemon;

use yii\base\NotSupportedException;
use yii\console\Controller;
use yii\helpers\Console;

/**
 * Class DaemonController
 *
 * @author Vladimir Yants <vladimir.yants@gmail.com>
 */
abstract class DaemonController extends Controller
{

    const EVENT_BEFORE_JOB = "EVENT_BEFORE_JOB";
    const EVENT_AFTER_JOB = "EVENT_AFTER_JOB";

    const EVENT_BEFORE_ITERATION = "event_before_iteration";
    const EVENT_AFTER_ITERATION = "event_after_iteration";

    /**
     * @var $demonize boolean Run controller as Daemon
     * @default false
     */
    public $demonize = false;

    /**
     * @var $isMultiInstance boolean allow daemon create a few instances
     * @see $maxChildProcesses
     * @default false
     */
    public $isMultiInstance = false;

    /**
     * @var $parentPID int main procces pid
     */
    protected $parentPID;

    /**
     * @var $maxChildProcesses int max daemon instances
     * @default 10
     */
    public $maxChildProcesses = 10;

    /**
     * @var $currentJobs [] array of running instances
     */
    protected static $currentJobs = [];

    /**
     * @var int Memory limit for daemon, must bee less than php memory_limit
     * @default 32M
     */
    private $memoryLimit = 268435456;

    /**
     * @var int used for soft daemon stop, set 1 to stop
     */
    private static $stopFlag = 0;

    /**
     * @var int Delay between task list checking
     * @default 5sec
     */
    protected $sleep = 5;

    protected $pidDir = "@runtime/daemons/pids";
    protected $logDir = "@runtime/daemons/logs";

    private $shortName = '';

    /**
     * Init function
     */
    public function init()
    {
        parent::init();

        //set PCNTL signal handlers
        pcntl_signal(SIGTERM, ['vyants\daemon\DaemonController', 'signalHandler']);
        pcntl_signal(SIGHUP, ['vyants\daemon\DaemonController', 'signalHandler']);
        pcntl_signal(SIGUSR1, ['vyants\daemon\DaemonController', 'signalHandler']);
        pcntl_signal(SIGCHLD, ['vyants\daemon\DaemonController', 'signalHandler']);

        $this->shortName = $this->shortClassName();
    }

    /**
     * Adjusting logger. You can override it.
     */
    protected function initLogger()
    {
        $targets = \Yii::$app->getLog()->targets;
        foreach ($targets as $name => $target) {
            $target->enabled = false;
        }
        $config = [
            'levels' => ['error', 'warning', 'trace', 'info'],
            'logFile' => \Yii::getAlias($this->logDir) . DIRECTORY_SEPARATOR . $this->shortName . '.log',
            'logVars'=>[],
            'except' => [
                'yii\db\*', // Don't include messages from db
            ],
        ];
        $targets['daemon'] = new \yii\log\FileTarget($config);
        \Yii::$app->getLog()->targets = $targets;
        \Yii::$app->getLog()->init();
    }

    /**
     * Daemon worker body
     *
     * @param $job
     * @return boolean
     */
    abstract protected function doJob($job);


    /**
     * Base action, you can\t override or create another actions
     *
     * @return boolean
     */
    final public function actionIndex()
    {
        if ($this->demonize) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->halt(self::EXIT_CODE_ERROR, 'pcntl_fork() rise error');
            } elseif ($pid) {
                $this->halt(self::EXIT_CODE_NORMAL);
            } else {
                posix_setsid();
                //close std streams (unlink console)
                if (is_resource(STDIN)) {
                    fclose(STDIN);
                    $stdIn = fopen('/dev/null', 'r');
                }
                if (is_resource(STDOUT)) {
                    fclose(STDOUT);
                    $stdOut = fopen('/dev/null', 'ab');
                }
                if (is_resource(STDERR)) {
                    fclose(STDERR);
                    $stdErr = fopen('/dev/null', 'ab');
                }
            }
        }
        //rename process
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            cli_set_process_title($this->getProcessName());
        } else {
            if (function_exists('setproctitle')) {
                setproctitle($this->getProcessName());
            } else {
                throw new NotSupportedException(
                    "Can't find cli_set_process_title or setproctitle function"
                );
            }
        }
        //run iterator
        return $this->loop();
    }

    protected function getProcessName()
    {
        return $this->shortName;
    }

    /**
     * Prevent non index action running
     *
     * @param \yii\base\Action $action
     * @return bool
     * @throws NotSupportedException
     */
    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {
            $this->initLogger();
            if ($action->id != "index") {
                throw new NotSupportedException(
                    "Only index action allowed in daemons. So, don't create and call another"
                );
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Возвращает доступные опции
     *
     * @param string $actionID
     * @return array
     */
    public function options($actionID)
    {
        return [
            'demonize',
            'taskLimit',
            'isMultiInstance',
            'maxChildProcesses'
        ];
    }

    /**
     * Extract current unprocessed jobs
     * You can extract jobs from DB (DataProvider will be great), queue managers (ZMQ, RabbiMQ etc), redis and so on
     *
     * @return array with jobs
     */
    abstract protected function defineJobs();


    /**
     * Fetch one task from array of tasks
     * @param Array
     * @return mixed one task
     */
    protected function defineJobExtractor(&$jobs)
    {
        return array_shift($jobs);
    }

    /**
     * Main iterator
     *
     * * @return boolean 0|1
     */
    final private function loop()
    {
        if (file_put_contents($this->getPidPath(), getmypid())) {
            $this->parentPID = getmypid();
            \Yii::trace('Daemon ' . $this->shortName . ' pid ' . getmypid() . ' started.');
            while (!self::$stopFlag && (memory_get_usage() < $this->memoryLimit)) {
                $this->trigger(self::EVENT_BEFORE_ITERATION);
                $this->renewConnections();
                $jobs = $this->defineJobs();
                if ($jobs && count($jobs)) {
                    while (($job = $this->defineJobExtractor($jobs)) !== null) {
                        //if no free workers, wait
                        if (count(static::$currentJobs) >= $this->maxChildProcesses) {
                            \Yii::trace('Reached maximum number of child processes. Waiting...');
                            while (count(static::$currentJobs) >= $this->maxChildProcesses) {
                                sleep(1);
                                pcntl_signal_dispatch();
                            }
                            \Yii::trace(
                                'Free workers found: ' .
                                ($this->maxChildProcesses - count(static::$currentJobs)) .
                                ' worker(s). Delegate tasks.'
                            );
                        }
                        pcntl_signal_dispatch();
                        $this->runDaemon($job);
                    }
                } else {
                    sleep($this->sleep);
                }
                pcntl_signal_dispatch();
                $this->trigger(self::EVENT_AFTER_ITERATION);
            }
            if (memory_get_usage() < $this->memoryLimit) {
                \Yii::trace('Daemon ' . $this->shortName . ' pid ' .
                    getmypid() . ' used ' . memory_get_usage() . ' bytes on ' . $this->memoryLimit .
                    ' bytes allowed by memory limit');
            }

            \Yii::info('Daemon ' . $this->shortClassName() . ' pid ' . getmypid() . ' is stopped.');

            if (file_exists($this->getPidPath())) {
                @unlink($this->getPidPath());
            } else {
                \Yii::error('Can\'t unlink pid file ' . $this->getPidPath());
            }

            return self::EXIT_CODE_NORMAL;
        }
        $this->halt(self::EXIT_CODE_ERROR, 'Can\'t create pid file ' . $this->getPidPath());
    }

    /**
     * Completes the process (soft)
     */
    public static function stop()
    {
        self::$stopFlag = 1;
    }

    /**
     * PCNTL signals handler
     *
     * @param $signo
     * @param null $pid
     * @param null $status
     */
    final function signalHandler($signo, $pid = null, $status = null)
    {
        switch ($signo) {
            case SIGTERM:
                //shutdown
                static::stop();
                break;
            case SIGHUP:
                //restart, not implemented
                break;
            case SIGUSR1:
                //user signal, not implemented
                break;
            case SIGCHLD:
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                while ($pid > 0) {
                    if ($pid && isset(static::$currentJobs[$pid])) {
                        unset(static::$currentJobs[$pid]);
                    }
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                break;
        }
    }


    /**
     * Tasks runner
     *
     * @param string $job
     * @return boolean
     */
    final public function runDaemon($job)
    {

        if ($this->isMultiInstance) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                return false;
            } elseif ($pid) {
                static::$currentJobs[$pid] = true;
            } else {
                $this->renewConnections();
                //child process must die
                $this->trigger(self::EVENT_BEFORE_JOB);
                if ($this->doJob($job)) {
                    $this->trigger(self::EVENT_AFTER_JOB);
                    $this->halt(self::EXIT_CODE_NORMAL);
                } else {
                    $this->trigger(self::EVENT_AFTER_JOB);
                    $this->halt(self::EXIT_CODE_ERROR, 'Child process #' . $pid . ' return error.');
                }
            }

            return true;
        } else {
            $this->trigger(self::EVENT_BEFORE_JOB);
            $status = $this->doJob($job);
            $this->trigger(self::EVENT_AFTER_JOB);

            return $status;
        }
    }

    /**
     * Stop process and show or write message
     *
     * @param $code int код завершения -1|0|1
     * @param $message string сообщение
     */
    protected function halt($code, $message = null)
    {
        if ($message !== null) {
            if ($code == self::EXIT_CODE_ERROR) {
                \Yii::error($message);
                if (!$this->demonize) {
                    $message = Console::ansiFormat($message, [Console::FG_RED]);
                }
            } else {
                \Yii::trace($message);
            }
            if (!$this->demonize) {
                $this->writeConsole($message);
            }
        }
        if ($code !== -1) {
            exit($code);
        }
    }

    /**
     * Renew connections
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    protected function renewConnections() {
        if(isset(\Yii::$app->db)) {
            \Yii::$app->db->close();
            \Yii::$app->db->open();
        }
    }

    /**
     * Show message in console
     *
     * @param $message
     */
    private function writeConsole($message)
    {
        $out = Console::ansiFormat('[' . date('d.m.Y H:i:s') . '] ', [Console::BOLD]);
        $this->stdout($out . $message . "\n");
    }

    /**
     * Get classname without namespace
     *
     * @return string
     */
    public function shortClassName()
    {
        $classname = $this->className();

        if (preg_match('@\\\\([\w]+)$@', $classname, $matches)) {
            $classname = $matches[1];
        }

        return $classname;
    }

    public function getPidPath()
    {
        $dir = \Yii::getAlias($this->pidDir);
        if (!file_exists($dir)) {
            mkdir($dir, 0744, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $this->shortName;
    }

}
