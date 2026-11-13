# gateway-login

Puts a Laravel app behind the **New Login gateway**. Nobody reaches the app, not even its
login page, without first coming through `login.example.com` with an approved computer.
The app's own login keeps working behind it.

## Setup

The package isn't on Packagist; Composer installs it straight from GitHub. In the app's
folder:

```bash
composer config repositories.gateway-login vcs https://github.com/djordjeveljkovic/gateway-login
composer require djordjeveljkovic/gateway-login:^1.0
```

The first line only adds this to the app's `composer.json`, so later `composer install`
runs (deploys) find the package too:

```json
"repositories": {
    "gateway-login": { "type": "vcs", "url": "https://github.com/djordjeveljkovic/gateway-login" }
}
```

Add four lines to `.env`. The phone shows them with a copy button: **Apps → (app) → App settings**.

```dotenv
GATEWAY_ENABLED=true
GATEWAY_URL=https://login.example.com
GATEWAY_APP_ID=main
GATEWAY_PUBLIC_KEY=...
```

Check it:

```bash
php artisan config:clear
php artisan gateway:check
```

That's all. No files to publish, no changes to `bootstrap/app.php` or routes.

Before you turn it on, approve at least one computer for the app on the phone, or use
**Open** on the phone, otherwise you lock yourself out too.

## What the package does

- **Wall.** It adds a middleware to the `web` group, ordered before any auth middleware.
  A visitor without a gateway session goes to `GATEWAY_URL/go/GATEWAY_APP_ID?return=<path>`.
  Background requests (POST, AJAX, Livewire, JSON) get a 419 instead, because they can't
  follow a redirect to another site.
- **Callback.** `GET /gateway/callback` checks the one-time token. It must have the gateway's
  Ed25519 signature, `aud` = app id, not be expired (30 s leeway), still be within the
  device's access, and not have been used before. Then the session marker is stored and the
  browser goes to `return`, which must be a path on this site.
- **Session.** The marker lasts at most `GATEWAY_SESSION_HOURS` (default and maximum 4) and never
  past the device's access end. After that the browser goes through the gateway again,
  which is invisible while the certificate is still valid.
- Routes outside the `web` group (`api`) are untouched. `/up` stays open.

## Options (`.env`)

| Variable | Default | |
|---|---|---|
| `GATEWAY_ENABLED` | `false` | Off = the package does nothing |
| `GATEWAY_URL` | | Gateway address |
| `GATEWAY_APP_ID` | | The app's **id** on the phone, not its name |
| `GATEWAY_PUBLIC_KEY` | | Gateway public key (base64) |
| `GATEWAY_ISSUER` | | Optional: only accept tokens from this gateway name |
| `GATEWAY_SESSION_HOURS` | `4` | 1–4 |
| `GATEWAY_EXCEPT` | `up` | Comma-separated paths that stay open (`Request::is()` patterns, e.g. `up,webhooks/*`) |
| `GATEWAY_PROTECT_WEB` | `true` | `false` = protect only routes that use the `gateway` middleware |

Requirements: Laravel 12, PHP 8.2+, a persistent cache (file, database or redis, used
to remember spent tokens) and a session driver other than `array`.

## In app code

```php
use GatewayLogin\Gateway;

Gateway::user();      // ['sub' => device id, 'name' => 'Pera Perić', 'org' => 'Firma doo', 'until' => ts] or null
Gateway::loginUrl();  // where to send someone to log in again
```

Events, for example to log sign-ins in the app itself:

```php
use GatewayLogin\Events\GatewayLogin;         // $sub, $name, $organization, $ip
use GatewayLogin\Events\GatewayLoginRefused;  // $reason, $ip

Event::listen(GatewayLogin::class, fn ($e) => logger("gateway login: {$e->name}"));
```

Refused links are also logged as `Gateway login refused: <reason>` (for example `token is for another app`).

## Customizing

```bash
php artisan vendor:publish --tag=gateway-config   # config/gateway.php
php artisan vendor:publish --tag=gateway-views    # resources/views/vendor/gateway/refused.blade.php
```

## Troubleshooting

| You see | Cause |
|---|---|
| "Link za prijavu je istekao…" and `token is for another app` in the log | `GATEWAY_APP_ID` isn't the id the phone shows for this app |
| `signature mismatch` | `GATEWAY_PUBLIC_KEY` is from another gateway, or was copied incompletely |
| `token expired` | The server clock is off by more than 30 seconds |
| `token already used` | The link was opened twice (some browsers or link previews fetch it first) |
| Connection dropped at the login address | That computer isn't approved for this app, or has no certificate |

## Tests

```bash
composer install && composer test
```
