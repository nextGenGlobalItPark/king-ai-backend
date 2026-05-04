<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament-panel gate that blocks access for users whose email has not
 * been verified.
 *
 * Whitelisting by URL path (not route name): Filament's auto-generated
 * route names from pages([]) registration vary by version, and matching
 * by name caused a redirect loop. Path matching is stable.
 *
 * VERSION MARKER: path-based-v2 — if you see this in the logs, this is
 * the version on disk. If you see the redirect loop AND don't see this
 * in the logs, the file on disk is the OLD route-name version.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        $allowedPathPatterns = [
            'admin/email-verify',
            'admin/logout',
            'livewire/update',
            'livewire/upload-file',
        ];

        // Diagnostic log — remove this line once the redirect loop issue is
        // confirmed resolved. The 'EnsureEmailVerified-v2' marker confirms
        // this exact file is being executed (vs a stale cached version).
        Log::debug('EnsureEmailVerified-v2', [
            'path' => $path,
            'user_id' => $user->id,
            'verified' => (bool) $user->email_verified_at,
        ]);

        foreach ($allowedPathPatterns as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
                return $next($request);
            }
        }

        return redirect('/admin/email-verify');
    }
}