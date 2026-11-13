<?php

namespace NewLogin\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use NewLogin\Laravel\Gateway;

/**
 * Keeps everyone out who has not logged in at the gateway (see config/gateway.php).
 * Does nothing unless GATEWAY_ENABLED=true, so other installations are unaffected.
 */
class RequireGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('gateway.enabled') || $request->routeIs('gateway.callback')) {
            return $next($request);
        }
        $except = (array) config('gateway.except', []);
        if ($except !== [] && $request->is(...$except)) {
            return $next($request);
        }

        $until = (int) $request->session()->get('gateway.until', 0);
        if ($until > now()->getTimestamp()) {
            return $next($request);
        }

        $request->session()->forget('gateway');

        // Background requests can't follow a redirect to another site. 419 makes Livewire
        // offer a reload, and the reload goes through the gateway again.
        if (! $request->isMethod('GET') || $request->ajax() || $request->hasHeader('X-Livewire') || $request->expectsJson()) {
            return response(__('Prijava je istekla. Osvežite stranicu.'), 419);
        }

        return redirect()->away(Gateway::loginUrl($request->getRequestUri()));
    }
}
