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
 * password-change. Critically, this middleware SKIPS itself entirely
 * when the user has not yet verified their email. Otherwise the two
 * middlewares would bounce the user between /admin/email-verify and
 * /admin/change-password forever:
 *
 *   - EnsureEmailVerified would let /admin/email-verify through
 *     (whitelisted) but redirect /admin/change-password
 *   - EnsureMustChangePassword would let /admin/change-password through
 *     (whitelisted) but redirect /admin/email-verify
 *
 * By gating on hasVerifiedEmail() FIRST, this middleware effectively
 * waits its turn — it only activates once email verification is done.
 *
 * Once must_change_password is cleared on the user, this middleware is
 * a no-op for the rest of their session.
 */
class EnsureMustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // CRITICAL: don't activate until email is verified. The
        // EnsureEmailVerified middleware (which runs BEFORE this one) will
        // handle the redirect to /admin/email-verify. If we tried to
        // redirect to /admin/change-password here while the user is
        // unverified, EnsureEmailVerified would immediately bounce them
        // back to /admin/email-verify, creating an infinite loop.
        if (! $user->hasVerifiedEmail()) {
            return $next($request);
        }

        // OAuth-authenticated sessions bypass this gate — OAuth users always
        // have must_change_password = false, but this is an explicit guard
        // consistent with the other middlewares.
        if ($request->session()->get('oauth_authenticated')) {
            return $next($request);
        }

        if (! $user->must_change_password) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        $allowedPathPatterns = [
            'admin/change-password',
            'admin/logout',
            'livewire/update',
            'livewire/upload-file',
        ];

        foreach ($allowedPathPatterns as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
                return $next($request);
            }
        }

        return redirect('/admin/change-password');
    }
}