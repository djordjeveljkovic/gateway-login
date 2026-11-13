<?php

use Illuminate\Support\Facades\Route;
use GatewayLogin\Http\Controllers\GatewayCallbackController;

// The gateway always sends users to /gateway/callback on the app's address.
Route::middleware('web')
    ->get('/gateway/callback', GatewayCallbackController::class)
    ->name('gateway.callback');
