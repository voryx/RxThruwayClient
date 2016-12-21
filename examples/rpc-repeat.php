<?php

use Rx\Observable;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', 'realm1');


//Repeat only after the proceeding call has completed
$source = $client
    ->register('com.myapp.example', function () {
        return 123;
    })
    ->mapTo($client->call('com.myapp.example'))
    ->switch()
    ->take(1)
    ->timeout(2000)
    ->repeatWhen(function (Observable $attempts) {
        return $attempts->delay(1000);
    })
    ->retryWhen(function (Observable $errors) {
        return $errors->delay(1000);
    });

$source->subscribe(
    function ($res) {
        list($args, $argskw, $details) = $res;

        echo 'Call result: ', $args[0], PHP_EOL;
    },
    function (Exception $e) {
        echo 'Call error: ', $e->getMessage(), PHP_EOL;
    },
    function () {
        echo 'Call completed', PHP_EOL;
    });
