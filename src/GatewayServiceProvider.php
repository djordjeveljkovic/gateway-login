<?php

namespace NewLogin\Laravel;

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use NewLogin\Laravel\Console\CheckCommand;
use NewLogin\Laravel\Http\Middleware\RequireGateway;

class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gateway.php', 'gateway');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/gateway.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'gateway');

        $this->app->make(Router::class)->aliasMiddleware('gateway', RequireGateway::class);

        if (config('gateway.protect_web', true)) {
            $kernel = $this->app->make(HttpKernel::class);
            if ($kernel instanceof Kernel) {
                // Ahead of every auth check, so not even the login page shows; after the
                // session starts, which the wall needs.
                $kernel->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, RequireGateway::class);
                $kernel->appendMiddlewareToGroup('web', RequireGateway::class);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
            $this->publishes([__DIR__.'/../config/gateway.php' => config_path('gateway.php')], 'gateway-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/gateway')], 'gateway-views');
        }
    }
}
