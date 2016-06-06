<?php

namespace Rx\Thruway;

use Rx\Observable;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use Rx\Subject\ReplaySubject;
use Thruway\Serializer\JsonSerializer;
use Rx\Thruway\Observable\CallObservable;
use Rx\Thruway\Observable\TopicObservable;
use Rx\Thruway\Observable\RegisterObservable;
use Rx\Thruway\Observable\WebSocketObservable;
use Rx\Extra\Observable\FromEventEmitterObservable;
use Thruway\Message\{
    Message, HelloMessage, WelcomeMessage
};

class Client
{
    private $url;
    private $loop;
    private $realm;
    private $session;
    private $messages;
    private $webSocket;
    private $serializer;

    public function __construct(string $url, string $realm, LoopInterface $loop = null)
    {
        $this->url        = $url;
        $this->loop       = $loop ?? \EventLoop\getLoop();
        $this->realm      = $realm;
        $this->webSocket  = (new WebSocketObservable($url, $this->loop))->repeat()->retry()->shareReplay(1);
        $this->serializer = new JsonSerializer();
        $this->messages   = $this->messagesFromWebSocket($this->webSocket)->share();
        $this->session    = new ReplaySubject(1);
        
        $this->setUpSession();
    }

    /**
     * @param Message $msg
     * @return Observable
     */
    public function sendMessage(Message $msg) :Observable
    {
        return $this->session
            ->flatMap(function () {
                return $this->webSocket;
            })
            ->take(1)
            ->doOnNext(function (WebSocket $webSocket) use ($msg) {
                $webSocket->send($this->serializer->serialize($msg));
            })
            ->flatMap(function () {
                return Observable::emptyObservable();
            });
    }

    /**
     * @param string $uri
     * @param array $args
     * @param array $argskw
     * @param array $options
     * @return CallObservable
     */
    public function call(string $uri, array $args = [], array $argskw = [], array $options = null) :CallObservable
    {
        return new CallObservable($uri, $this->messages, [$this, 'sendMessage'], $args, $argskw, $options);
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @return RegisterObservable
     */
    public function register(string $uri, callable $callback, array $options = []) :RegisterObservable
    {
        return new RegisterObservable($uri, $callback, $this->messages, [$this, 'sendMessage'], $options);
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @return RegisterObservable
     */
    public function registerExtended(string $uri, callable $callback, array $options = []) :RegisterObservable
    {
        return new RegisterObservable($uri, $callback, $this->messages, [$this, 'sendMessage'], $options, true);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return TopicObservable
     */
    public function topic(string $uri, array $options = []) :TopicObservable
    {
        return new TopicObservable($uri, $options, $this->messages, [$this, 'sendMessage']);
    }

    /**
     * Emits new sessions onto a session subject
     */
    private function setUpSession()
    {
        $helloMsg = new HelloMessage($this->realm, new \stdClass());

        $this->webSocket
            ->map(function (WebSocket $ws) use ($helloMsg) {
                return $ws->send($this->serializer->serialize($helloMsg));
            })
            ->flatMap(function () {
                return $this->messages;
            })
            ->filter(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            })
            ->subscribe($this->session);
    }

    /**
     * @param Observable $webSocket
     * @return Observable
     */
    private function messagesFromWebSocket(Observable $webSocket) :Observable
    {
        return $webSocket
            ->flatMap(function (WebSocket $webSocket) {
                return (new FromEventEmitterObservable($webSocket, "message", "error", "close"));
            })
            ->map(function ($msg) {
                return $this->serializer->deserialize($msg[0]);
            });
    }
}