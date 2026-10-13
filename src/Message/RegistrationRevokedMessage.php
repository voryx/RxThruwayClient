<?php

namespace Rx\Thruway\Message;

use Thruway\Message\Traits\DetailsTrait;
use Thruway\Message\UnregisteredMessage;

/**
 * Router-initiated UNREGISTERED message, sent by a Dealer to revoke a registration
 * (WAMP advanced profile: registration revocation).
 *
 * <code>[UNREGISTERED, 0, {"registration": Registration|id, "reason": string}]</code>
 *
 * The request id is always 0 because the Dealer has no UNREGISTER request to reply to.
 * The reason is optional free text supplied by the router.
 */
final class RegistrationRevokedMessage extends UnregisteredMessage
{
    use DetailsTrait;

    public function __construct($requestId, $details)
    {
        parent::__construct($requestId);
        $this->setDetails($details);
    }

    public function getRegistrationId(): ?int
    {
        return $this->details->registration ?? null;
    }

    public function getReason(): ?string
    {
        return $this->details->reason ?? null;
    }

    public function getAdditionalMsgFields()
    {
        return [$this->getRequestId(), $this->getDetails()];
    }
}
