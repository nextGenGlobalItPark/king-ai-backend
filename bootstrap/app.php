<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(HandleCors::class);

        $middleware->api(prepend: [
            SubstituteBindings::class,
            'throttle:api',
        ]);
        $middleware->alias([
            'auth:sanctum' => EnsureFrontendRequestsAreStateful::class,
            'throttle' => ThrottleRequests::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Cleanup old generation assets (default 30 day retention).
        // Runs daily at 03:00 to keep disk usage in check on the VPS.
        $schedule->command('generations:cleanup --days=30')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();