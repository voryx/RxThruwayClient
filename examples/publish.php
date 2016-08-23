<?php

use Rx\Observable;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', "realm1");

$source = Observable::range(1, 30);

$client->publish('com.myapp.hello', $source);
