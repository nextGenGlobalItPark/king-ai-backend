<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures a Filament-authenticated user with 2FA enabled has passed the
 * 2FA challenge for the current session.
 *
 * Runs AFTER Filament's Authenticate middleware. If the session is missing
 * the `two_factor_passed` flag and the user has 2FA enabled, redirect to
 * the challenge page. The challenge page itself is whitelisted (otherwise
 * we'd loop), as is the logout route (so a user can always escape).
 *
 * The session flag is cleared on logout (StartSession + AuthenticateSession
 * tear down the whole session), so re-login always re-challenges.
 */
class EnsureTwoFactorChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not logged in (shouldn't happen — Authenticate runs first — but be safe)
        // or 2FA not enabled for this user: nothing to do.
        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        // Already passed the challenge this session.
        if ($request->session()->get('two_factor_passed') === true) {
            return $next($request);
        }

        // Whitelist routes that must remain accessible during the challenge:
        // the challenge page itself, and logout (so users can back out).
        $currentRoute = $request->route()?->getName();
        $allowedRoutes = [
            'filament.admin.pages.two-factor-challenge',
            'filament.admin.auth.logout',
        ];

        if (in_array($currentRoute, $allowedRoutes, true)) {
            return $next($request);
        }

        return redirect()->route('filament.admin.pages.two-factor-challenge');
    }
}
