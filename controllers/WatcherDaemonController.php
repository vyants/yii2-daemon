<?php

namespace vyants\daemon\controllers;

use vyants\daemon\DaemonController;
use yii\console\ExitCode;

/**
 * watcher-daemon - check another daemons and run it if need
 *
 * @author Vladimir Yants <vladimir.yants@gmail.com>
 */
abstract class WatcherDaemonController extends DaemonController
{
    /**
     * @var string subfolder in console/controllers
     */
    public $daemonFolder = 'daemons';

    /**
     * @var boolean flag for first iteration
     */
    protected $firstIteration = true;

    /**
     * Prevent double start
     *
     * @throws \yii\base\ExitException
     */
    public function init()
    {
        $pid_file = $this->getPidPath();
        if (file_exists($pid_file) && ($pid = file_get_contents($pid_file)) && file_exists("/proc/$pid")) {
            $this->halt(ExitCode::UNSPECIFIED_ERROR, 'Another Watcher is already running.');
        }
        parent::init();
    }

    /**
     * Job processing body
     *
     * @param $job array
     *
     * @return boolean
     * @throws \yii\base\ExitException
     */
    protected function doJob($job)
    {
        $pid_file = $this->getPidPath($job['daemon']);

        \Yii::debug('Check daemon ' . $job['daemon']);
        if (file_exists($pid_file)) {
            $pid = file_get_contents($pid_file);
            if ($this->isProcessRunning($pid)) {
                if ($job['enabled']) {
                    \Yii::debug('Daemon ' . $job['daemon'] . ' running and working fine');

                    return true;
                } else {
                    \Yii::warning('Daemon ' . $job['daemon'] . ' running, but disabled in config. Send SIGTERM signal.');
                    if (isset($job['hardKill']) && $job['hardKill']) {
                        posix_kill($pid, SIGKILL);
                    } else {
                        posix_kill($pid, SIGTERM);
                    }

                    return true;
                }
            }
        }
        \Yii::error('Daemon pid not found.');
        if ($job['enabled']) {
            \Yii::debug('Try to run daemon ' . $job['daemon'] . '.');
            $command_name = $job['daemon'] . DIRECTORY_SEPARATOR . 'index';
            //flush log before fork
            $this->flushLog(true);
            //run daemon
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->halt(ExitCode::UNSPECIFIED_ERROR, 'pcntl_fork() returned error');
            } elseif ($pid === 0) {
                $this->cleanLog();
                \Yii::$app->requestedRoute = $command_name;
                \Yii::$app->runAction("$command_name", ['demonize' => 1]);
                $this->halt(0);
            } else {
                $this->initLogger();
                \Yii::debug('Daemon ' . $job['daemon'] . ' is running with pid ' . $pid);
            }
        }
        \Yii::debug('Daemon ' . $job['daemon'] . ' is checked.');

        return true;
    }

    /**
     * @return array
     */
    protected function defineJobs()
    {
        if ($this->firstIteration) {
            $this->firstIteration = false;
        } else {
            sleep($this->sleep);
        }

        return $this->getDaemonsList();
    }

    /**
     * Daemons for check. Better way - get it from database
     * [
     *  ['daemon' => 'one-daemon', 'enabled' => true]
     *  ...
     *  ['daemon' => 'another-daemon', 'enabled' => false]
     * ]
     * @return array
     */
    abstract protected function getDaemonsList();

    /**
     * @param $pid
     *
     * @return bool
     */
    public function isProcessRunning($pid)
    {
        return file_exists("/proc/$pid");
    }
}
