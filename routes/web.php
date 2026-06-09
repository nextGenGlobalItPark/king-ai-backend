<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\OAuthController;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/admin/auth/{provider}/redirect', [OAuthController::class, 'redirect'])
    ->name('auth.oauth.redirect');
Route::get('/admin/auth/callback', [OAuthController::class, 'callback'])
    ->name('auth.oauth.callback');