<?php

use Rx\Thruway\Client;
use Thruway\Message\ResultMessage;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', 'realm1');

$client
    ->registerExtended('com.myapp.example', function ($args, $argskw, $details, $invocationMsg) {
        return 1234567;
    })
    ->subscribe(
        function () {
            echo 'Registered ', PHP_EOL;
        },
        function (Exception $e) {
            echo 'Register error: ', $e->getMessage(), PHP_EOL;
        },
        function () {
            echo 'Register completed', PHP_EOL;
        });

$client
    ->call('com.myapp.example', [123], ['foo' => 'bar'])
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
