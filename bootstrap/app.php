<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

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
            'auth:sanctum'  => EnsureFrontendRequestsAreStateful::class,
            'throttle'      => ThrottleRequests::class,
            // Spatie roles/permissions middleware. Usage in routes:
            //   ->middleware('role:admin')
            //   ->middleware('permission:manage-templates')
            //   ->middleware('permission:manage-templates|manage-categories')   // OR
            'role'          => RoleMiddleware::class,
            'permission'    => PermissionMiddleware::class,
            // Supabase JWT token verification for API routes.
            // Usage: ->middleware('supabase.auth')
            'supabase.auth' => \App\Http\Middleware\VerifySupabaseToken::class,
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
        // Render spatie's permission/role denial as our standard API
        // envelope instead of the default Symfony 403 page. Without
        // this, an API call hitting a permission gate gets HTML back,
        // which breaks JSON clients.
        $exceptions->render(function (\Spatie\Permission\Exceptions\UnauthorizedException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'You do not have permission to perform this action.',
                    'errors'  => null,
                ], 403);
            }
            return null; // fall through to default rendering for non-JSON
        });
    })->create();