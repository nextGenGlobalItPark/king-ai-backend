<?php

use App\Http\Controllers\FilamentOauthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Filament (admin panel) OAuth routes
|--------------------------------------------------------------------------
|
| These are plain web routes, NOT inside the Filament panel's own route
| registration, because:
|   1. The redirect route is unauthenticated (user isn't logged in yet).
|   2. The callback must log the user in via Auth::login() and set the
|      session flag before Filament's auth middleware runs.
|
| They use the standard 'web' middleware group (session + CSRF) automatically.
| GET requests are exempt from CSRF verification.
|
*/
Route::prefix('admin/oauth')->group(function () {
    Route::get('/{provider}', [FilamentOauthController::class, 'redirect'])
        ->name('filament.oauth.redirect');
    Route::get('/{provider}/callback', [FilamentOauthController::class, 'callback'])
        ->name('filament.oauth.callback');
});
