<?php

namespace GatewayLogin\Events;

/** Someone came in through the gateway. `sub` is the device id (or `admin:<id>` from the phone). */
class GatewayLogin
{
    public function __construct(
        public readonly string $sub,
        public readonly string $name,
        public readonly string $organization,
        public readonly string $ip,
    ) {}
}
