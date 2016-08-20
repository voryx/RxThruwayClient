<?php

use Rx\Observable;
use Rx\Thruway\Client;
use Rx\Thruway\Observer\PublishObserver;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', "realm1");

$topic = $client->topic('some.topic');

$topic->onNext('start');

Observable::range(1, 30)->subscribe($topic);


//You can also subscribe directly onto a PublishObserver
Observable::range(1, 30)->subscribe(new PublishObserver('some.topic', [], $client));
