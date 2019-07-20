<?php declare(strict_types=1);

namespace Rx\Thruway\Subject;

use function EventLoop\getLoop;
use React\Socket\ConnectorInterface;
use Rx\DisposableInterface;
use Rx\Observable;
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
    private $openObserver;
    private $closeObserver;
    private $serializer;

    public function __construct(string $url, array $protocols = [], Subject $openObserver = null, Subject $closeObserver = null, ConnectorInterface $connector)
    {
        $this->openObserver  = $openObserver ?? new Subject();
        $this->closeObserver = $closeObserver ?? new Subject();
        $this->serializer    = new JsonSerializer();
        $this->sendSubject   = new ReplaySubject();

        $this->ws = new Client($url, false, $protocols, getLoop(), $connector);
    }

    public function onNext($value)
    {
        $this->sendSubject->onNext($this->serializer->serialize($value));
    }

    protected function _subscribe(ObserverInterface $observer): DisposableInterface
    {
        return $this->ws
            ->do(function ($ms) {

                $bufferedMessages = $this->sendSubject;

                // Now that the connection has been established, use the message subject directly.
                $this->sendSubject = $ms;

                // Replay buffered messages onto the MessageSubject
                $bufferedMessages->subscribe($ms);

                $this->openObserver->onNext($ms);
            })
            ->switch()
            ->finally(function () {
                // The connection has closed, so start buffering messages util it reconnects.
                $this->sendSubject = new ReplaySubject();
                $this->closeObserver->onNext(0);
            })
            ->repeatWhen(function (Observable $a) {
                return $a->do(function () {
                    echo "Reconnecting\n";
                })->delay(1000);
            })
            ->retryWhen(function (Observable $a) {
                return $a->do(function (\Throwable $e) {
                    echo "Error {$e->getMessage()}, Reconnecting\n";
                })->delay(1000);
            })
            ->map([$this->serializer, 'deserialize'])
            ->subscribe($observer);
    }
}
