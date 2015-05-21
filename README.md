Daemons system for Yii2
=======================
Extension provides functionality for simple daemons creation and control

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist vyants/yii2-daemon "*"
```

or add

```
"vyants/yii2-daemon": "*"
```

to the require section of your `composer.json` file.

### Setting WatcherDaemon
WatcherDaemon is the main daemon and provides from box. This daemon check another daemons and run, if it need.
Do the following steps:

1. Create in you console controllers path file WatcherDaemonController.php with following content:
```
<?php

namespace console\controllers;

class WatcherDaemonController extends \vyants\daemon\controllers\WatcherDaemonController
{
    /**
     * @return array
     */
    protected function defineJobs()
    {
        sleep($this->sleep);
        //TODO: modify list, or get it from config, it does not matter
        $daemons = [
            ['className' => 'OneDaemonController', 'enabled' => true],
            ['className' => 'AnotherDaemonController', 'enabled' => false]
        ];
        return $daemons;
    }
}
```
2. No one checks the Watcher. Watcher should run continuously. Add it to your crontab:
```
* * * * * /path/to/yii/project/yii watcher-daemon --demonize=1
```
Watcher can't start twice, only one instance can work in the one moment.

Usage
-----
### Create new daemons
1. Create in you console controllers path file {NAME}DaemonController.php with following content:
```
<?php

namespace console\controllers;

use \vyants\daemon\DaemonController;

class {NAME}DaemonController extends DaemonController
{
    /**
     * @return array
     */
    protected function defineJobs()
    {
        /*
        TODO: return task list, extracted from DB, queue managers and so on. 
        Extract tasks in small portions, to reduce memory usage.
        */
    }
    /**
     * @return jobtype
     */
    protected function doJob($job)
    {
        /*
        TODO: implement you logic
        Don't forget to mark task as completed in your task source
        */
    }
}
```
2. Implement logic. 
3. Add new daemon to daemons list in watcher.

### Daemon settings (propeties)
In your daemon you can override parent properties:
* `$demonize` - if 0 daemon is not running as daemon, only as simple console application. It's needs for debug.
* `$memoryLimit` - if daemon reach this limit - daemon stop work. It prevent memory leaks. After stopping WatcherDaemon run this daemon again.
* `$sleep` - delay between checking for new task, daemon will not sleep if task list is full.
* `$pidDir` - dir where daemons pids is located
* `$logDir` - dir where daemons logs is located
* `$isMultiInstance` - this option allow daemon create self copy for each task. That is, the daemon can simultaneously perform multiple tasks. This is useful when one task requires some time and server resources allows perform many such task.
* `$maxChildProcesses` - only if `$isMultiInstance=true`. The maximum number of daemons instances. If the maximum number is reached - the system waits until at least one child process to terminate.

If you want to change logging preferences, you may override the function initLogger. Example:

```
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
            'logFile' => \Yii::getAlias($this->logDir) . DIRECTORY_SEPARATOR . $this->shortClassName() . '.log',
            'logVars'=>[], // Don't log all variables
            'exportInterval'=>1, // Write each message to disk
            'except' => [
                'yii\db\*', // Don't include messages from db
            ],
        ];
        $targets['daemon'] = new \yii\log\FileTarget($config);
        \Yii::$app->getLog()->targets = $targets;
        \Yii::$app->getLog()->init();
        // Flush each message
        \Yii::$app->getLog()->flushInterval = 1;
    }
```
    
    
### Installing Proctitle on PHP < 5.5.0

You will need proctitle extension on your server to be able to isntall yii2-daemon. For a debian 7 you can use these commands:

```
# pecl install channel://pecl.php.net/proctitle-0.1.2
# echo "extension=proctitle.so" > /etc/php5/mods-available/proctitle.ini
# php5enmod proctitle
```
