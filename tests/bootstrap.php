<?php

if (file_exists($file = __DIR__ . '/../vendor/autoload.php')) {
    require $file;
} else {
    throw new RuntimeException('Install dependencies to run test suite.');
}

/**
 * The default scheduler is the EventLoopScheduler, which is asynchronous.
 * For testing we need to block at `subscribe`, so we need to switch the default to the ImmediateScheduler.
 */
\Rx\Scheduler::setDefaultFactory(function () {
    return new \Rx\Scheduler\ImmediateScheduler();
});