<?php

namespace Rx\Thruway\Observer;

use Exception;
use Rx\Observable;
use Ratchet\Client\WebSocket;
use Rx\Observer\AbstractObserver;
use Rx\Disposable\CompositeDisposable;
use Thruway\Serializer\SerializerInterface;
use Rx\Thruway\Observable\WampChallengeException;

class ChallengeObserver extends AbstractObserver
{
    private $webSocket;
    private $serializer;
    private $disposable;

    public function __construct(Observable $webSocket, SerializerInterface $serializer)
    {
        $this->webSocket  = $webSocket;
        $this->serializer = $serializer;
        $this->disposable = new CompositeDisposable();
    }

    protected function completed()
    {
        //Not sure if we should dispose here or not.
        // $this->disposable->dispose();
    }

    protected function next($msg)
    {
        $sub = $this->webSocket
            ->take(1)
            ->subscribeCallback(function (WebSocket $ws) use ($msg) {
                $ws->send($this->serializer->serialize($msg));
            }
            //@todo add logging for errors
            );
        $this->disposable->add($sub);
    }

    protected function error(Exception $ex)
    {
        if ($ex instanceof WampChallengeException) {
            $sub = $this->webSocket
                ->take(1)
                ->subscribeCallback(function (WebSocket $ws) use ($ex) {
                    $ws->send($this->serializer->serialize($ex->getErrorMessage())); //This probably needs to be an Abort message
                }
                //@todo add logging
                );

            $this->disposable->add($sub);
        }
    }

}
