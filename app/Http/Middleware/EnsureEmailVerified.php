<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament-panel gate that blocks access for users whose email has not
 * been verified.
 *
 * Runs AFTER Filament's Authenticate middleware. If the logged-in user
 * has a null email_verified_at, they're redirected to a Filament page
 * where they can enter the 6-digit code that was emailed to them. The
 * verification page itself is whitelisted (otherwise we'd loop), as is
 * the logout route.
 *
 * This check intentionally runs BEFORE EnsureMustChangePassword and
 * EnsureTwoFactorChallenge — email verification is the prerequisite for
 * everything else.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $currentRoute = $request->route()?->getName();
        $allowedRoutes = [
            'filament.admin.pages.email-verify',
            'filament.admin.auth.logout',
        ];

        if (in_array($currentRoute, $allowedRoutes, true)) {
            return $next($request);
        }

        return redirect()->route('filament.admin.pages.email-verify');
    }
}
