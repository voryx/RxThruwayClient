<?php
/**
 * Find the auto loader file
 */
$locations = [
    __DIR__ . '/../',
    __DIR__ . '/../../',
    __DIR__ . '/../../../',
    __DIR__ . '/../../../../',
];


foreach ($locations as $location) {

    $file = $location . "vendor/autoload.php";

    if (file_exists($file)) {
        $loader = require_once $file;
        $loader->addPsr4('Rx\\Thruway\\Tests\\', __DIR__);
        break;
    }
}

/**
 * The default scheduler is the EventLoopScheduler, which is asynchronous.
 * For testing we need to block at `subscribe`, so we need to switch the default to the ImmediateScheduler.
 */
\Rx\Scheduler::setDefault(new \Rx\Scheduler\ImmediateScheduler());