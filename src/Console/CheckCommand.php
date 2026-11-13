<?php

namespace NewLogin\Laravel\Console;

use Illuminate\Console\Command;
use NewLogin\Laravel\Gateway;

/** `php artisan gateway:check`: is this app set up correctly for the gateway? */
class CheckCommand extends Command
{
    protected $signature = 'gateway:check';

    protected $description = 'Check the New Login gateway settings of this app';

    public function handle(): int
    {
        $problems = 0;
        $ok = fn (string $m) => $this->line("  <fg=green>✓</> $m");
        $bad = function (string $m) use (&$problems) {
            $problems++;
            $this->line("  <fg=red>✗</> $m");
        };
        $warn = fn (string $m) => $this->line("  <fg=yellow>!</> $m");

        $this->line('');
        config('gateway.enabled') ? $ok('GATEWAY_ENABLED=true') : $warn('GATEWAY_ENABLED is off: the app is open to everyone');

        $url = (string) config('gateway.url');
        str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL)
            ? $ok("GATEWAY_URL $url")
            : $bad('GATEWAY_URL must be the gateway address, e.g. https://login.example.com');

        $app = (string) config('gateway.app_id');
        preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $app)
            ? $ok("GATEWAY_APP_ID $app (must match the app's id on the phone, not its name)")
            : $bad('GATEWAY_APP_ID is missing or not an id (lowercase letters, digits, dashes)');

        $key = base64_decode((string) config('gateway.public_key'), true);
        $key !== false && strlen($key) === 32
            ? $ok('GATEWAY_PUBLIC_KEY is a valid Ed25519 key ('.substr((string) config('gateway.public_key'), 0, 8).'…)')
            : $bad('GATEWAY_PUBLIC_KEY is missing or not 32 bytes of base64 (phone: Settings → gateway key)');

        $cache = (string) config('cache.default');
        in_array($cache, ['array', 'null'], true)
            ? $bad("CACHE_STORE=$cache cannot remember used login links; use file, database or redis")
            : $ok("cache store '$cache' remembers used login links");

        $session = (string) config('session.driver');
        $session === 'array'
            ? $bad('SESSION_DRIVER=array forgets every login; use file, database, cookie or redis')
            : $ok("session driver '$session'");

        str_starts_with((string) config('app.url'), 'https://')
            ? $ok('APP_URL is https')
            : $warn('APP_URL is not https; the gateway only sends users to https addresses');

        config('gateway.protect_web')
            ? $ok('every web route is behind the gateway'.(config('gateway.except') ? ' except: '.implode(', ', config('gateway.except')) : ''))
            : $warn("GATEWAY_PROTECT_WEB=false: only routes with the 'gateway' middleware are protected");

        $this->line('');
        $this->line('  Users who are not logged in go to:  '.Gateway::loginUrl());
        $this->line('  The gateway sends them back to:     '.rtrim((string) config('app.url'), '/').'/gateway/callback');
        $this->line('');

        if ($problems) {
            $this->error("  $problems problem(s) to fix.");

            return self::FAILURE;
        }
        $this->info('  Looks good.');

        return self::SUCCESS;
    }
}
