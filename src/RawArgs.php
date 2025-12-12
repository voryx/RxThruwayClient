<?php

namespace Rx\Thruway;

class RawArgs
{
    private $args;
    private $kwargs;

    public function __construct(array $args = [], array $kwargs = [])
    {
        $this->args   = $args;
        $this->kwargs = $kwargs;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getKwArgs(): array
    {
        return $this->kwargs;
    }
}
