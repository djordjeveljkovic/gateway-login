<?php

namespace NewLogin\Laravel;

/**
 * Small helpers for app code:
 *   Gateway::user()    who came in through the gateway (null when the gateway is off)
 *   Gateway::loginUrl() where to send someone to log in again
 */
class Gateway
{
    /** @return array{sub: string, name: string, org: string, until: int}|null */
    public static function user(): ?array
    {
        $g = session('gateway');

        return is_array($g) && ($g['until'] ?? 0) > now()->getTimestamp() ? $g : null;
    }

    public static function loginUrl(?string $return = null): string
    {
        $url = config('gateway.url').'/go/'.rawurlencode((string) config('gateway.app_id'));
        if ($return !== null && $return !== '/' && strlen($return) <= 512) {
            $url .= '?return='.rawurlencode($return);
        }

        return $url;
    }
}
