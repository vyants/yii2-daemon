<?php

namespace vyants\daemon\controllers;

use vyants\daemon\DaemonController;

/**
 * watcher-daemon - check another daemons and run it if need
 *
 * @author Vladimir Yants <vladimir.yants@gmail.com>
 */
abstract class WatcherDaemonController extends DaemonController
{
    /**
     * Daemons for check
     * [
     *  ['className' => 'OneDaemonController', 'enabled' => true]
     *  ...
     *  ['className' => 'AnotherDaemonController', 'enabled' => false]
     * ]
     * @var $daemonsList Array
     */
    public $daemonsList = [];

    public $daemonFolder = '';

    public function init()
    {
        $pidFile = \Yii::getAlias($this->pidDir) . DIRECTORY_SEPARATOR . $this->shortClassName();
        if (file_exists($pidFile)) {
            $pid = file_get_contents($pidFile);
            exec("ps -p $pid", $output);
            if (count($output) > 1) {
                $this->halt(self::EXIT_CODE_ERROR, 'Another Watcher is already running.');
            }
        }
        parent::init();
    }

    /**
     * Job processing body
     *
     * @param $job array
     * @return boolean
     */
    protected function doJob($job)
    {
        $pidfile = \Yii::getAlias($this->pidDir) . DIRECTORY_SEPARATOR . $job['className'];

        \Yii::trace('Check daemon '.$job['className']);
        if (file_exists($pidfile)) {
            $pid = file_get_contents($pidfile);
            if ($this->isProcessRunning($pid)) {
                if ($job['enabled']) {
                    \Yii::trace('Daemon ' . $job['className']. ' running and working fine');
                    return true;
                } else {
                    \Yii::warning('Daemon ' . $job['className']. ' running, but disabled in config. Send SIGTERM signal.');
                    if(isset($job['hardKill']) && $job['hardKill']){
                        posix_kill($pid, SIGKILL);
                    } else {
                        posix_kill($pid, SIGTERM);
                    }
                    return true;
                }
            }
        }
        \Yii::trace('Daemon pid not found.');
        if($job['enabled']) {
            \Yii::trace('Try to run daemon ' . $job['className']. '.');
            $command_name = $this->getCommandNameBy($job['className']);
            //run daemon
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->halt(self::EXIT_CODE_ERROR, 'pcntl_fork() returned error');
            } elseif (!$pid) {
                \Yii::trace('Daemon '.$job['className'] .' is running.');
            } else {
                \Yii::$app->runAction("$command_name", ['demonize' => 1]);
            }

        }
        \Yii::trace('Daemon '.$job['className'] .' is checked.');

        return true;
    }

    /**
     * Return array of daemons
     *
     * @return array
     */
    protected function defineJobs() {
        sleep($this->sleep);
        return $this->daemonsList;
    }

    protected function getCommandNameBy($className) {
        $command = strtolower(
            preg_replace_callback('/(?<!^)(?<![A-Z])[A-Z]{1}/',
                function ($matches) {
                    return '-' . $matches[0];
                },
                str_replace('Controller', '', $className)
            )
        );

        if(!empty($this->daemonFolder)) {
            $command = $this->daemonFolder . DIRECTORY_SEPARATOR. $command;
        }

        return $command . DIRECTORY_SEPARATOR . 'index';
    }

    /**
     * @param $pid
     * @return bool
     */
    public function isProcessRunning($pid){
        return !!posix_getpgid($pid);
    }
}
