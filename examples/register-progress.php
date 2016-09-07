<?php

use Rx\Observer\CallbackObserver;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', "realm1");

$client->register('com.myapp.example', function ($x) {
    return \Rx\Observable::interval(300, new \Rx\Scheduler\EventLoopScheduler(\EventLoop\getLoop()))->doOnNext(function () {
        echo ".";
    });
}, ["progress" => true])->subscribeCallback(
    function () {
        echo "Registered ", PHP_EOL;
    },
    function (Exception $e) {
        echo "Register error: ", $e->getMessage(), PHP_EOL;
    },
    function () {
        echo "Register completed", PHP_EOL;
    }
);

