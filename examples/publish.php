<?php

use Rx\Observable;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$driver = new \Amp\Loop\LoopFactory();
\Interop\Async\Loop::setFactory($driver);

$client = new Client('ws://127.0.0.1:9090', 'realm1');

$source = Observable::interval(1000);

$client->publish('com.myapp.hello', $source);
