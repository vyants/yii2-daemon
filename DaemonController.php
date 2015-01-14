<?php

namespace vyants\daemon;

use yii\console\Controller;
use yii\helpers\Console;

/**
 * Class DaemonController
 *
 * @author Vladimir Yants <vladimir.yants@gmail.com>
 */
abstract class DaemonController extends Controller
{

    const EVENT_BEFORE_TASK = "event_before_task";
    const EVENT_AFTER_TASK = "event_after_task";

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
    protected $currentJobs = [];

    /**
     * @var int Memory limit for daemon, must bee less than php memory_limit
     * @default 32M
     */
    private $memoryLimit = 268435456;

    /**
     * @var int used for soft daemon stop, set 1 to stop
     */
    private $stopFlag = 0;

    /**
     * @var int Delay between task list checking
     * @default 5sec
     */
    protected $sleep = 5;

    protected $pidDir = "@runtime/daemons/pids";
    protected $logDir = "@runtime/daemons/logs";

    /**
     * Init function
     */
    public function init()
    {
        parent::init();

        //set PCNTL signal handlers
        pcntl_signal(SIGTERM, ['vyants\daemon\DaemonController', 'onSignal']);
        pcntl_signal(SIGHUP, ['vyants\daemon\DaemonController', 'onSignal']);
        pcntl_signal(SIGUSR1, ['vyants\daemon\DaemonController', 'onSignal']);
        pcntl_signal(SIGCHLD, ['vyants\daemon\DaemonController', 'onSignal']);

        $this->initLogger();


    }

    /**
     * Adjusting logger. You can override it.
     */
    protected function initLogger() {
        $targets = \Yii::$app->getLog()->targets;
        foreach ($targets as $name => $target) {
            if ($name != 'daemon') {
                $target->enabled = false;
            }
        }
        if(!isset($targets['daemon'])) {
            $config = [
                'levels' => ['error', 'warning', 'trace'],
                'logFile' => \Yii::getAlias($this->pidDir) . DIRECTORY_SEPARATOR . $this->shortClassName() . '.log'
            ];
            $targets['daemon'] = new \yii\log\FileTarget($config);
            \Yii::$app->getLog()->targets = $targets;
        }
        $targets['daemon']->init();
    }

    /**
     * Daemon worker body
     *
     * @param $job
     * @return boolean
     */
    abstract protected function doJob($job);


    /**
     * Базовый экшен демона
     * Нельзя переопределять, нельзя создавать другие
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
                fclose(STDIN);
                fclose(STDOUT);
                fclose(STDERR);
                $this->STDIN = fopen('/dev/null', 'r');
                $this->STDOUT = fopen('/dev/null', 'ab');
                $this->STDERR = fopen('/dev/null', 'ab');
            }
        }
        //мы либо в дочернем процессе, либо это не демон, в любом случае, стартуем итератор
        return $this->loop();
    }

    /**
     * Возвращает доступные опции
     *
     * @param string $actionID
     * @return array
     */
    public function options($actionID)
    {
        if ($actionID == 'index') {
            return [
                'demonize',
                'taskLimit',
                'isMultiInstance',
                'maxChildProcesses'
            ];
        }
        return [];
    }

    /**
     * @return Array with task
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
            \Yii::trace('Daemon ' . $this->shortClassName() . ' pid ' . getmypid() . ' started.');
            while (!$this->stopFlag && (memory_get_usage() < $this->memoryLimit)) {
                $jobs = $this->defineJobs();
                if ($jobs && count($jobs)) {
                    while (($job = $this->defineJobExtractor($jobs)) !== null) {
                        //if no free workers, wait
                        if (count($this->currentJobs) >= $this->maxChildProcesses) {
                            \Yii::trace('Max child proccess is reached. Wait.');
                            while (count($this->currentJobs) >= $this->maxChildProcesses) {
                                sleep(1);
                                pcntl_signal_dispatch();
                            }
                            \Yii::trace('Free workers found: '
                                . $this->maxChildProcesses - count($this->currentJobs) . ' worker(s). Delegate tasks.');
                        }
                        pcntl_signal_dispatch();
                        $this->run($job);
                    }
                } else {
                    sleep($this->sleep);
                }
                pcntl_signal_dispatch();
            }
            if (memory_get_usage() < $this->memoryLimit) {
                \Yii::warning('Daemon ' . $this->shortClassName() . ' pid ' .
                    getmypid() . ' is reached memory limit ' . $this->memoryLimit .
                    ', memory usage: ' . memory_get_usage()
                );
            }

            \Yii::trace('Daemon ' . $this->shortClassName() . ' pid ' . getmypid() . ' is stopped now.');

            unlink(\Yii::$app->params['pidDir'] . DIRECTORY_SEPARATOR . $this->shortClassName());

            return self::EXIT_CODE_NORMAL;
        }
        $this->halt(self::EXIT_CODE_ERROR, 'Can\'t create pid file '.$this->getPidPath());
    }

    /**
     * Starting
     *
     * @return boolean 0|1
     */
    final public function start()
    {

    }

    /**
     * Устанавливает stopFlag
     */
    final public function stop()
    {
        $this->stopFlag = 1;
    }

    /**
     * PCNTL signals handler
     *
     * @param $signo
     * @param null $pid
     * @param null $status
     */
    final function onSignal($signo, $pid = null, $status = null)
    {
        switch ($signo) {
            case SIGTERM:
                //shutdown
                $this->stop();
                break;
            case SIGHUP:
                //restart, not implemented
                break;
            case SIGUSR1:
                //restart, not implemented
                break;
            case SIGCHLD:
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                while ($pid > 0) {
                    if ($pid && isset($this->currentJobs[$pid])) {
                        unset($this->currentJobs[$pid]);
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
    final public function run($job)
    {

        if ($this->isMultiInstance) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                return false;
            } elseif ($pid) {
                $this->currentJobs[$pid] = true;
            } else {
                //child process must die
                $this->trigger(self::EVENT_BEFORE_TASK);
                if ($this->doJob($job)) {
                    $this->trigger(self::EVENT_AFTER_TASK);
                    $this->halt(self::EXIT_CODE_NORMAL);
                } else {
                    $this->trigger(self::EVENT_AFTER_TASK);
                    $this->halt(self::EXIT_CODE_ERROR, 'Child process #' . $pid . ' return error.');
                }
            }

            return true;
        } else {
            $this->trigger(self::EVENT_BEFORE_TASK);
            $status = $this->doJob($job);
            $this->trigger(self::EVENT_AFTER_TASK);

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
        if($code !== -1) {
            exit($code);
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

    public function getPidPath(){
        $dir = \Yii::getAlias($this->pidDir);
        if (!file_exists($dir)) {
            mkdir($dir, 0744, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $this->shortClassName();
    }

}
