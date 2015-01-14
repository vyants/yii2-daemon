<?php

namespace vyants\daemon\controllers;

use vyants\daemon\DaemonController;

/**
 * watcher-daemon - check another daemons and run it
 *
 * @author Vladimir Yants <vladimir.yants@gmail.com>
 */
class WatcherDaemonController extends DaemonController
{
    public function init()
    {
        $pidfile = \Yii::$app->params['pidDir'] . DIRECTORY_SEPARATOR . $this->shortClassName();
        if (file_exists($pidfile)) {
            $pid = file_get_contents($pidfile);
            exec("ps -p $pid", $output);
            if (count($output) > 1) {
                $this->halt(self::EXIT_CODE_ERROR, 'Another Watcher already running.');
            }
        }
        parent::init();
    }

    /**
     * Тело обработки очереди
     *
     * @param $job[]
     * @return boolean
     */
    protected function doJob($job)
    {
        $output = [];
        $pidfile = \Yii::$app->params['pidDir'] . DIRECTORY_SEPARATOR . $job['className'];

        \Yii::trace('Проверяем демон '.$job['className']);
        if (file_exists($pidfile)) {
            $pid = file_get_contents($pidfile);
            exec("ps -p $pid", $output);
            if (count($output) > 1) {
                if (current($job)['enabled']) {
                    \Yii::trace('Демон ' . $job['className']. ' работает, никаких действий не трубется');
                    return true;
                } else {
                    \Yii::warning('Демон ' . $job['className']. ' работает, но в отключен в конфиге. Отправляем SIGTERM сигнал');
                    posix_kill($pid, SIGTERM);
                    return true;
                }
            }
        }
        \Yii::trace('Нет pid-а.');
        if($job['enabled']) {
            \Yii::trace('Пытаемся запустить демон ' . $job['className']. '.');
            $command_name = strtolower(
                preg_replace_callback('/(?<!^)(?<![A-Z])[A-Z]{1}/',
                    function ($matches) {
                        return '-' . $matches[0];
                    },
                    str_replace('Controller', '',$job['className'])
                )
            );
            exec("./yii $command_name --demonize=1 >/dev/null 2>&1 &");
            $this->stderr("./yii $command_name --demonize=1 >/dev/null 2>&1 &");
            \Yii::trace('Команда запуска демона ' . $job['className']. ' выполнена.');
        }
        \Yii::trace('Проверка демона '.$job['className'] .' завершена.');

        return true;
    }

    /**
     * @return array
     */
    protected function defineJobs()
    {
        return \Yii::$app->params['watchingDaemons'];
    }

    /**
     * @param $jobs[]
     * @return array
     */
    protected function defineJobExtractor(&$jobs)
    {
        return parent::defineJobExtractor($jobs);
    }
}