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
2. No one ckeck watcher. Watcher must every time runnig, then add crontab:
```* * * * * /path/to/yii/project/yiic watcher-daemon --demonize=1```
Watcher can't start twice, only one instance can work in the one moment.

3. Well done.

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
        Extract tasks in small portions, such as 50, to prevent memory occupy.
        */
    }
    /**
     * @return jobtype
     */
    protected function doJob($job)
    {
        /*
        TODO: implement you logic
        Don't forget check task as completed in your task source
        */
    }
}
```
2. Implement logic. Add new daemon to daemons list in watcher.

### Daemon settings (propeties)
In your daemon you can ovveride parent properties:
* `$demonize` - if 0 daemon is not running as daemon, only as simple console application. It needs for debug.
* `$memoryLimit` - if daemon reach this limit - daemon stop work. It needs for prevent memory leaks. After stopping WatcherDaemon run this daemon again.
* `$sleep` - delay between cheking for new task, daemon will not sleep if task list is full.
* `$pidDir` - dir where daemons pids is located
* `$logDir` - dir where daemons logs is located
* `$isMultiInstance` - this option allow daemon create self copy for each task. That is, the daemon can simultaneously perform multiple tasks. This is useful when one task requires some time and server resources allows perform many such task.
* `$maxChildProcesses` - only if `$isMultiInstance=true`. The maximum daemons instances. If maximum is reached - wait for free instances.
