<?php

use Rx\Observer\CallbackObserver;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', "realm1");

$client->topic('com.myapp.hello')->subscribe(new CallbackObserver(
        function ($res) {
            list($args, $argskw, $details) = $res;

            echo "Result: ", $args[0], PHP_EOL;
        },
        function (Exception $e) {
            echo "Error: ", $e->getMessage(), PHP_EOL;
        },
        function () {
            echo "Completed", PHP_EOL;
        })
);
