<?php

use Rx\Observable;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', "realm1");

//$source = Observable::interval(1000);

//$x = $client->publish('com.myapp.hello', $source);


$client->register('com.myapp.example', function ($x) {
    return $x;
})->subscribe(new \Rx\Observer\CallbackObserver(
    function () {
        echo "Registered ", PHP_EOL;
    },
    function (Exception $e) {
        echo "Register error: ", $e->getMessage(), PHP_EOL;
    },
    function () {
        echo "Register completed", PHP_EOL;
    }), new \Rx\Scheduler\EventLoopScheduler(\EventLoop\getLoop())

);

\EventLoop\addTimer(1, function () use ($client) {
    echo "a";
//    $client->publish('com.myapp.hello', "hi");
    $client->close();
});