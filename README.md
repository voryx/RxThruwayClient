# RxPHP WAMP Client

This project is a WAMP v2 client written in PHP that uses RxPHP Observables instead of promises and event emitters.

If you don't know what [WAMP](http://wamp-proto.org/) is, you should read up on it.

If you don't know what [RxPHP](https://github.com/ReactiveX/RxPHP) or [ReactiveExtensions](http://reactivex.io/) is, you're missing out...

## Installation

```BASH
composer require rx/thruway-client
```

## Usage

```PHP
use Rx\Observable;
use Rx\Thruway\Client;

require __DIR__ . '/vendor/autoload.php';

$wamp = new Client('ws://127.0.0.1:9090', 'realm1');
```

## Call

```PHP
$wamp->call('add.rpc', [1, 2])
    ->map(function (Thruway\Message\ResultMessage $r) {
        return $r->getArguments()[0];
    })
    ->subscribe(function ($r) {
        echo $r;
    });
```

## Register

```PHP
$wamp->register('add.rpc', function ($a, $b) { return $a + $b; })->subscribe();
```

>If the Registration Handler throws an exception, `thruway.error.invocation_exception` is returned to the caller. If you would like to allow more specific error messages, you must throw a `WampErrorException` or, if using observable sequences that are returned from the RPC, you can `onError` a `WampErrorException`.

## Publish to topic

```PHP
$wamp->publish('example.topic', 'some value');
$wamp->publish('example.topic', Observable::interval(1000)); // you can also publish an observable
```

## Subscribe to topic

```PHP
$wamp->topic('example.topic')
    ->map(function(Thruway\Message\EventMessage $m) {
        return $m->getArguments()[0];
    })
    ->subscribe(function ($v) { echo $v; });
```