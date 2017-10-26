<?php

use Rx\Thruway\Client;
use Thruway\Message\ResultMessage;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', 'realm1');

$client
    ->progressiveCall('com.myapp.example', [1234])
    ->take(10)
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
