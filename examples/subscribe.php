<?php

use Rx\Thruway\Client;
use Thruway\Message\EventMessage;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', 'realm1');

$client
    ->topic('com.myapp.hello')
    ->subscribe(
        function (EventMessage $ev) {
            echo 'Call result: ', $ev->getArguments()[0], PHP_EOL;
        },
        function (Exception $e) {
            echo 'Error: ', $e->getMessage(), PHP_EOL;
        },
        function () {
            echo 'Completed', PHP_EOL;
        });
