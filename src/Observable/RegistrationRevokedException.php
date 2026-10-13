<?php

namespace Rx\Thruway\Observable;

use Thruway\WampErrorException;

/**
 * Emitted as the error of a register observable when the Dealer revokes the registration
 * (WAMP advanced profile: registration revocation). The registration no longer exists on
 * the router, so re-registering requires resubscribing, e.g. with retry() or retryWhen().
 */
final class RegistrationRevokedException extends WampErrorException
{
    const ERROR_URI = 'thruway.error.registration_revoked';

    private $registrationId, $reason;

    public function __construct(?int $registrationId, ?string $reason = null, $details = null)
    {
        $this->registrationId = $registrationId;
        $this->reason         = $reason;

        parent::__construct(self::ERROR_URI, $reason === null ? [] : [$reason], null, $details);
    }

    public function getRegistrationId(): ?int
    {
        return $this->registrationId;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
