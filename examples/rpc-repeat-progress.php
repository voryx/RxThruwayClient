<?php

use Rx\Observable;
use Rx\Thruway\Client;
use Thruway\Message\ResultMessage;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', 'realm1');

$client
    ->progressiveRegister('com.myapp.example', function () {
        return Observable::interval(500)->finally(function () {
            echo 'Far end observable has completed', PHP_EOL;
        });
    })
    ->subscribe();

$client
    ->progressiveCall('com.myapp.example')
    ->take(1)
    ->repeatWhen(function (Observable $attempts) {
        return $attempts->delay(1000)->take(10);
    })
    ->subscribe(
        function (ResultMessage $res) {
            echo 'Call result: ', $res->getArguments()[0], PHP_EOL;
        },
        function (Throwable $e) {
            echo 'Call error: ', $e->getMessage(), PHP_EOL;
        },
        function () {
            echo 'Call completed', PHP_EOL;
        });


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
