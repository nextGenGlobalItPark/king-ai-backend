<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures a Filament-authenticated user with 2FA enabled has passed the
 * 2FA challenge for the current session.
 *
 * Runs AFTER Filament's Authenticate, EnsureEmailVerified, and
 * EnsureMustChangePassword. Critically, this middleware skips itself
 * if any earlier prerequisite has not been satisfied — otherwise the
 * middlewares would bounce the user between their respective gate pages
 * forever (each tries to redirect away from the others' pages).
 *
 * The session flag is cleared on logout (StartSession + AuthenticateSession
 * tear down the whole session), so re-login always re-challenges.
 */
class EnsureTwoFactorChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // OAuth-authenticated sessions bypass this gate entirely. The OAuth
        // provider already verified the user's identity; we don't re-challenge
        // with 2FA for OAuth logins per product spec.
        if ($request->session()->get('oauth_authenticated')) {
            return $next($request);
        }

        // Wait for prior gates: email verification and forced password
        // change. Only this middleware's predecessors should redirect
        // until those are done. Trying to redirect to /admin/two-factor-
        // challenge while the user is still unverified or holding a
        // temp password would create a redirect loop with those gates.
        if (! $user->hasVerifiedEmail() || $user->must_change_password) {
            return $next($request);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        // Already passed the challenge this session.
        if ($request->session()->get('two_factor_passed') === true) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        $allowedPathPatterns = [
            'admin/two-factor-challenge',
            'admin/oauth',
            'admin/logout',
            'livewire/update',
            'livewire/upload-file',
        ];

        foreach ($allowedPathPatterns as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
                return $next($request);
            }
        }

        return redirect('/admin/two-factor-challenge');
    }
}