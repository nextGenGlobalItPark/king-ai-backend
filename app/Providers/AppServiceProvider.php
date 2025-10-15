<?php

namespace App\Providers;

use App\Exceptions\Handler;
use App\Services\FCMPushService;
use App\Services\PushNotificationServiceInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
        $this->app->singleton(ExceptionHandler::class, function (Application $app) {
            return new Handler($app);
        });

        $this->app->bind(PushNotificationServiceInterface::class, function () {
            $provider = config('push.provider', 'fcm');
            return match($provider) {
                'fcm' => new FCMPushService(),
                default => throw new \InvalidArgumentException("Invalid push provider: {$provider}"),
            };
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}