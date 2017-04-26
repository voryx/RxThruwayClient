<?php

namespace Rx\Thruway\Observable;

use Thruway\Message\ErrorMessage;
use Thruway\Message\InvocationMessage;
use Thruway\WampErrorException;

final class WampInvocationException extends WampErrorException
{
    private $invocationMessage;

    public function __construct(InvocationMessage $msg, $errorUri = 'thruway.error.invocation_exception', $arguments = null, $argumentsKw = null, $details = null)
    {
        $this->invocationMessage = $msg;

        parent::__construct($errorUri, $arguments, $argumentsKw, $details);
    }

    public static function withInvocationMessageAndWampErrorException(InvocationMessage $invocationMessage, WampErrorException $wee)
    {
        return new static(
            $invocationMessage,
            $wee->getErrorUri(),
            $wee->getArguments(),
            $wee->getArgumentsKw(),
            $wee->getDetails());
    }

    /**
     * @return mixed
     */
    public function getErrorMessage()
    {
        $errorMessage = ErrorMessage::createErrorMessageFromMessage($this->invocationMessage, $this->getErrorUri());
        $errorMessage->setArguments($this->getArguments());
        $errorMessage->setArgumentsKw($this->getArgumentsKw());
        $errorMessage->setDetails($this->getDetails());

        return $errorMessage;
    }
}
