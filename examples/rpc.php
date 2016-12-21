<?php

use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', "realm1");

$client
    ->register('com.myapp.example', function ($x) {
        return $x;
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
        }
    );

$client
    ->call('com.myapp.example', [1234])
    ->subscribe(
        function ($res) {
            list($args, $argskw, $details) = $res;

            echo 'Call result: ', $args[0], PHP_EOL;
        },
        function (Exception $e) {
            echo 'Call error: ', $e->getMessage(), PHP_EOL;
        },
        function () {
            echo 'Call completed', PHP_EOL;
        }
    );
