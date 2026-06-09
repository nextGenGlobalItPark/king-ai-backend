<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SupabaseGoogleController;


Route::get('/', function () {
    return view('welcome');
});


Route::get('/admin/auth/google/redirect', [SupabaseGoogleController::class, 'redirect'])
    ->name('auth.google.redirect');

Route::get('/admin/auth/callback', [SupabaseGoogleController::class, 'callback'])
    ->name('auth.google.callback');