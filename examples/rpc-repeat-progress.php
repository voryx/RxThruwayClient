<?php

require __DIR__ . '/../vendor/autoload.php';

$client    = new \Rx\Thruway\Client("ws://127.0.0.1:9090", "realm1");
$scheduler = new \Rx\Scheduler\EventLoopScheduler(\EventLoop\getLoop());

$source = $client
    ->register('com.myapp.example', function () {
        return \Rx\Observable::fromArray([1, 2, 3, 4]);
    }, ["progress" => true])
    ->flatMapTo($client->call('com.myapp.example', [], [], ["receive_progress" => true])
        ->repeatWhen(function (\Rx\Observable $attempts) {
            return $attempts->delay(1000);
        })
    );

$source->subscribe(new \Rx\Observer\CallbackObserver(
    function ($res) {
        list($args, $argskw, $details) = $res;

        echo "Call result: ", $args[0], PHP_EOL;
    },
    function (Exception $e) {
        echo "Call error: ", $e->getMessage(), PHP_EOL;
    },
    function () {
        echo "Call completed", PHP_EOL;
    }), $scheduler);
