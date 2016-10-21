<?php

use Rx\Observable;
use Rx\Observer\CallbackObserver;
use Rx\Scheduler\EventLoopScheduler;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client    = new Client("ws://127.0.0.1:9090", "realm1");
$scheduler = new EventLoopScheduler(\EventLoop\getLoop());

$client->progressiveRegister('com.myapp.example', function () {
    return Observable::interval(500);
})->subscribe(new CallbackObserver(), $scheduler);


$client->progressiveCall('com.myapp.example', [], [])
    ->take(5)
    ->repeatWhen(function (Observable $attempts) {
        return $attempts->delay(1000)->take(1);
    })
    ->subscribe(new CallbackObserver(
        function ($res) {
            list($args, $argskw, $details) = $res;

            echo "Call result: ", $args[0], PHP_EOL;
        },
        function (Exception $e) {
            echo "Call error: ", $e->getMessage(), PHP_EOL;
        },
        function () {
            echo "Call completed", PHP_EOL;
        }), $scheduler);


//Output
//Call result: 0
//Call result: 1
//Call result: 2
//Call result: 3
//Call result: 4
//Call result: 0
//Call result: 1
//Call result: 2
//Call result: 3
//Call result: 4
//Call completed
