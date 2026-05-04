<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces admin-created users to change their temporary password before
 * they can use the rest of the panel.
 *
 * Runs AFTER EnsureEmailVerified — we always want email-verified before
 * password-change, since the password change page assumes the email is
 * trustworthy enough to set credentials with.
 *
 * Once must_change_password is cleared on the user, this middleware is
 * a no-op for the rest of their session.
 */
class EnsureMustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        $currentRoute = $request->route()?->getName();
        $allowedRoutes = [
            'filament.admin.pages.change-password',
            'filament.admin.auth.logout',
        ];

        if (in_array($currentRoute, $allowedRoutes, true)) {
            return $next($request);
        }

        return redirect()->route('filament.admin.pages.change-password');
    }
}
