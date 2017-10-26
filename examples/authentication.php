<?php

use Rx\Observable;
use Rx\Thruway\Client;
use Thruway\Message\ChallengeMessage;
use Thruway\Message\ResultMessage;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', 'somerealm', ['authmethods' => ['simplysimple']]);

$client->onChallenge(function (Observable $challenge) {
    return $challenge->map(function (ChallengeMessage $args) {
        return 'letMeIn';
    });
});

$client
    ->register('com.myapp.example', function ($x) {
        return $x;
    })
    ->subscribe(function () {
        echo 'Registered ', PHP_EOL;
    });

$client
    ->call('com.myapp.example', [1234])
    ->subscribe(function (ResultMessage $res) {
        echo 'Call result: ', $res->getArguments()[0], PHP_EOL;
    });
