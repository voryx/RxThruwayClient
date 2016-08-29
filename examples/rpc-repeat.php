<?php

require __DIR__ . '/../vendor/autoload.php';

$client    = new \Rx\Thruway\Client("ws://127.0.0.1:9090", "realm1");
$scheduler = new \Rx\Scheduler\EventLoopScheduler(\EventLoop\getLoop());

//Repeat only after the proceeding call has completed
$source = $client
    ->register('com.myapp.example', function () {
        return 123;
    })
    ->mapTo($client->call('com.myapp.example')
        ->repeatWhen(function (\Rx\Observable $attempts) {
            return $attempts
                ->doOnNext(function () {
                    echo "Attempt made", PHP_EOL;
                })
                ->delay(1000);
        }))
    ->switchLatest();

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
