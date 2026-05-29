<?php

namespace App\Http\Controllers;

use App\Services\OauthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Handles the Filament (admin panel) OAuth2 PKCE flow.
 *
 * Identical PKCE mechanics to OauthController but the outcome is a web
 * session (Auth::login) instead of a Sanctum token, and the final
 * redirect goes to /admin rather than a mobile deep link.
 *
 * Session flag:
 *   After a successful OAuth login we store 'oauth_authenticated = true'
 *   in the session. The three Filament auth-gate middlewares
 *   (EnsureEmailVerified, EnsureMustChangePassword, EnsureTwoFactorChallenge)
 *   all check this flag and short-circuit, skipping their gates entirely.
 *   This is correct: the OAuth provider has already verified the user's
 *   identity and email; there is no password to change; and 2FA challenge
 *   is explicitly bypassed for OAuth logins per product spec.
 *
 * Access control:
 *   Only users with 'access-admin-panel' permission (i.e. the admin role)
 *   may complete the Filament OAuth flow. Regular users who try to OAuth
 *   into /admin are redirected back to the login page with an error.
 */
class FilamentOauthController extends Controller
{
    public function __construct(private readonly OauthService $oauthService) {}

    /**
     * GET /admin/oauth/{provider}
     *
     * Initiates the OAuth flow by redirecting the browser to Supabase.
     */
    public function redirect(string $provider): RedirectResponse
    {
        if (! $this->oauthService->isValidProvider($provider)) {
            abort(404, "OAuth provider '{$provider}' is not supported.");
        }

        $callbackUrl = url("/admin/oauth/{$provider}/callback");
        $redirectUrl = $this->oauthService->buildRedirectUrl($provider, $callbackUrl);

        return redirect()->away($redirectUrl);
    }

    /**
     * GET /admin/oauth/{provider}/callback
     *
     * Receives the code from Supabase, resolves/creates the local User,
     * logs them in via a web session, and redirects to /admin.
     */
    public function callback(string $provider, Request $request): RedirectResponse
    {
        if (! $this->oauthService->isValidProvider($provider)) {
            abort(404, "OAuth provider '{$provider}' is not supported.");
        }

        // Handle provider-level errors (e.g. user cancelled).
        if ($request->query('error')) {
            $detail = $request->query('error_description') ?? 'OAuth sign-in was cancelled.';
            return $this->adminError($detail);
        }

        $code  = $request->query('code');
        $state = $request->query('_s');

        if (! $code || ! $state) {
            return $this->adminError('OAuth callback is missing required parameters.');
        }

        try {
            $supabaseData = $this->oauthService->exchangeCodeForUser($code, $state);
            $user         = $this->oauthService->findOrCreateUser($supabaseData, $provider);
        } catch (\RuntimeException $e) {
            return $this->adminError($e->getMessage());
        }

        // Only admins may access the Filament panel.
        if (! $user->hasPermissionTo('access-admin-panel')) {
            return $this->adminError('This account does not have admin access.');
        }

        Auth::login($user);

        // Mark this session as OAuth-authenticated. All three auth-gate
        // middlewares skip their checks when this flag is present.
        $request->session()->put('oauth_authenticated', true);
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }

    /**
     * Redirect back to the admin login page with an error message.
     */
    private function adminError(string $message): RedirectResponse
    {
        return redirect('/admin/login')->withErrors(['oauth' => $message]);
    }
}
