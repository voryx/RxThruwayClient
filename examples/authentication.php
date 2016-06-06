<?php

use Rx\Observable;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', "somerealm", ["authmethods" => ["simplysimple"]]);

$client->onChallenge(function (Observable $challenge) {
    return $challenge->map(function ($args) {
        list($method, $extra) = $args;
        return "letMeIn";
    });
});

$client->register('com.myapp.example', function ($x) {
    return $x;
})->subscribeCallback(function () {
    echo "Registered ", PHP_EOL;
});

$client->call('com.myapp.example', [1234])->subscribeCallback(function ($res) {
    list($args, $argskw, $details) = $res;

    echo "Call result: ", $args[0], PHP_EOL;
});
