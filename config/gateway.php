<?php

/*
| Central login (New Login gateway).
|
| When enabled, nobody reaches this app (not even its login page) without first
| logging in at the gateway with an approved computer. The gateway sends the user
| back to /gateway/callback with a one-time signed token; this app then keeps a
| gateway session for at most `session_hours`, after which the browser is sent to
| the gateway again (invisible while the computer's access is still valid).
|
| The four lines for .env are shown on the admin phone: Apps → (app) → App settings.
|   GATEWAY_ENABLED=true
|   GATEWAY_URL=https://login.example.com
|   GATEWAY_APP_ID=main            (the app's id on the phone, not its name)
|   GATEWAY_PUBLIC_KEY=...         (the gateway's public key, base64)
|
| Check the setup with:  php artisan gateway:check
*/

return [
    'enabled' => (bool) env('GATEWAY_ENABLED', false),

    'url' => rtrim((string) env('GATEWAY_URL', ''), '/'),

    'app_id' => (string) env('GATEWAY_APP_ID', ''),

    // Ed25519 public key, base64 (32 bytes).
    'public_key' => (string) env('GATEWAY_PUBLIC_KEY', ''),

    // Optional: the gateway's name; tokens from any other issuer are refused.
    'issuer' => env('GATEWAY_ISSUER'),

    // How long one gateway login lasts here. Capped at 4 hours.
    'session_hours' => min(4, max(1, (int) env('GATEWAY_SESSION_HOURS', 4))),

    // Add the wall to the `web` middleware group automatically. Set to false to
    // attach the `gateway` middleware to routes yourself.
    'protect_web' => (bool) env('GATEWAY_PROTECT_WEB', true),

    // Paths that stay reachable without the gateway (Request::is() patterns), e.g. a
    // health check or a webhook that lives in the web group.
    'except' => array_filter(explode(',', (string) env('GATEWAY_EXCEPT', 'up'))),
];
