<?php declare(strict_types=1);

namespace Rx\Thruway\Subject;

use Rx\DisposableInterface;
use Rx\ObserverInterface;
use Rx\Subject\ReplaySubject;
use Rx\Subject\Subject;
use Rx\Websocket\Client;
use Thruway\Serializer\JsonSerializer;

/**
 * Class WebSocket - WebSocket wrapper that queues messages while the connection is being established.
 */
final class WebSocketSubject extends Subject
{
    private $ws;
    private $sendSubject;
    private $loop;
    private $openObserver;
    private $closeObserver;
    private $serializer;

    public function __construct(string $url, array $protocols = [], Subject $openObserver = null, Subject $closeObserver = null)
    {
        $this->openObserver  = $openObserver ?? new Subject();
        $this->closeObserver = $closeObserver ?? new Subject();
        $this->serializer    = new JsonSerializer();
        $this->loop          = \EventLoop\getLoop();
        $this->sendSubject   = new ReplaySubject();

        $this->ws = new Client($url, false, $protocols, $this->loop);
    }

    public function onNext($value)
    {
        $this->sendSubject->onNext($this->serializer->serialize($value));
    }

    protected function _subscribe(ObserverInterface $observer): DisposableInterface
    {
        return $this->ws
            ->do(function ($ms) {
                // Replay buffered messages onto the MessageSubject
                $this->sendSubject->subscribe($ms);

                // Now that the connection has been established, use the message subject directly.
                $this->sendSubject = $ms;
            })
            ->do([$this->openObserver, 'onNext'])
            ->finally(function () {
                // The connection has closed, so start buffering messages util it reconnects.
                $this->sendSubject = new ReplaySubject();
                $this->closeObserver->onNext(0);
            })
            ->mergeAll()
            ->map([$this->serializer, 'deserialize'])
            ->subscribe($observer);
    }
}
