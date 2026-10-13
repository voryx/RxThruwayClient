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

### Registration revocation

If the router revokes a registration (WAMP advanced profile `registration_revocation`), the register observable errors with a `Rx\Thruway\Observable\RegistrationRevokedException`. It extends `WampErrorException` and exposes the revoked registration id and the router's reason, if one was given. The registration no longer exists on the router at that point, so no `UNREGISTER` is sent. To register again, resubscribe:

```PHP
$wamp->register('add.rpc', function ($a, $b) { return $a + $b; })
    ->retryWhen(function (Observable $errors) {
        return $errors->filter(function (\Throwable $e) {
            return $e instanceof RegistrationRevokedException;
        });
    })
    ->subscribe();
```

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