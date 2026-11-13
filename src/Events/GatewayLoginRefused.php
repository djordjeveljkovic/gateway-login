<?php

namespace NewLogin\Laravel\Events;

/** A login link was refused: expired, used twice, for another app, or forged. */
class GatewayLoginRefused
{
    public function __construct(
        public readonly string $reason,
        public readonly string $ip,
    ) {}
}
