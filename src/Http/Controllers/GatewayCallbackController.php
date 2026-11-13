<?php

namespace NewLogin\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use NewLogin\Laravel\Events\GatewayLogin;
use NewLogin\Laravel\Events\GatewayLoginRefused;
use NewLogin\Laravel\Gateway;
use NewLogin\Laravel\GatewayToken;

/** Where the gateway sends the user after login: /gateway/callback?token=...&return=/path */
class GatewayCallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(config('gateway.enabled'), 404);

        try {
            $identity = GatewayToken::verify((string) $request->query('token', ''));
        } catch (RuntimeException $e) {
            Log::warning('Gateway login refused: '.$e->getMessage(), ['ip' => $request->ip()]);
            event(new GatewayLoginRefused($e->getMessage(), (string) $request->ip()));

            return response()
                ->view('gateway::refused', ['retry' => Gateway::loginUrl()], 403)
                ->header('Referrer-Policy', 'no-referrer');
        }

        $session = $request->session();
        // A different device taking over this browser starts from a clean session.
        if ($session->has('gateway.sub') && $session->get('gateway.sub') !== $identity['sub']) {
            $session->invalidate();
        }
        $session->regenerate();
        $session->put('gateway', [
            'sub' => $identity['sub'],
            'name' => $identity['name'],
            'org' => $identity['org'],
            'until' => min(now()->getTimestamp() + config('gateway.session_hours') * 3600, $identity['access_until']),
        ]);
        event(new GatewayLogin($identity['sub'], $identity['name'], $identity['org'], (string) $request->ip()));

        // Redirecting right away also drops the token from the address bar.
        return redirect(self::safeReturn($request->query('return')))->header('Referrer-Policy', 'no-referrer');
    }

    private static function safeReturn(mixed $path): string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')
            || strlen($path) > 512 || preg_match('/[\\\\\s\x00-\x1f\x7f]/', $path)) {
            return '/';
        }

        return $path;
    }
}
