<?php

use Rx\Observable;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', 'realm1');

$client
    ->register('com.myapp.example', function ($x) {
        return Observable::interval(300)->do(function () {
            echo '.';
        });
    }, ['progress' => true])
    ->subscribe(
        function () {
            echo 'Registered ', PHP_EOL;
        },
        function (Throwable $e) {
            echo 'Register error: ', $e->getMessage(), PHP_EOL;
        },
        function () {
            echo 'Register completed', PHP_EOL;
        });
