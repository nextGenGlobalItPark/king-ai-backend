<?php

namespace App\Http\Controllers;

use App\Services\OauthService;
use App\Traits\ResponseTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles the API-side OAuth2 PKCE flow (for mobile and web clients).
 *
 * The full flow:
 *   1. Client opens a browser / WebView to GET /api/auth/oauth/{provider}.
 *   2. This controller redirects to Supabase, which handles Google/Apple.
 *   3. Supabase redirects back to GET /api/auth/oauth/{provider}/callback.
 *   4. We exchange the code, find/create the user, issue a Sanctum token,
 *      and redirect to the app's deep link:
 *          vidioking://auth/callback?token={sanctum_token}
 *   5. The mobile app intercepts the deep link, reads the token, and
 *      stores it for all subsequent API calls.
 *
 * On failure the deep link carries ?error={message} instead of ?token.
 *
 * Note: 2FA is intentionally NOT challenged for OAuth sign-ins — see the
 * product spec. The OAuth provider is considered sufficient authentication.
 */
class OauthController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly OauthService $oauthService) {}

    /**
     * GET /api/auth/oauth/{provider}
     *
     * Initiates the OAuth flow by redirecting the browser to Supabase.
     * Generates a fresh PKCE pair and stores the verifier in cache.
     */
    public function redirect(string $provider): RedirectResponse
    {
        if (! $this->oauthService->isValidProvider($provider)) {
            abort(404, "OAuth provider '{$provider}' is not supported.");
        }

        $callbackUrl = url("/api/auth/oauth/{$provider}/callback");
        $redirectUrl = $this->oauthService->buildRedirectUrl($provider, $callbackUrl);

        return redirect()->away($redirectUrl);
    }

    /**
     * GET /api/auth/oauth/{provider}/callback
     *
     * Receives the authorization code from Supabase, exchanges it for a
     * Supabase user, resolves or creates the local User, issues a Sanctum
     * token, and redirects to the mobile deep link.
     *
     * Query params injected by Supabase:
     *   code  — the authorization code to exchange
     *   _s    — our state UUID (embedded in redirect_to; identifies the
     *           PKCE verifier in cache)
     *   error, error_description — present when the user cancelled or
     *           Supabase encountered an error
     */
    public function callback(string $provider, Request $request): RedirectResponse
    {
        if (! $this->oauthService->isValidProvider($provider)) {
            abort(404, "OAuth provider '{$provider}' is not supported.");
        }

        // Handle provider-level errors (e.g. user cancelled the consent screen).
        if ($request->query('error')) {
            $detail = $request->query('error_description') ?? 'OAuth sign-in was cancelled.';
            return $this->mobileError($detail);
        }

        $code  = $request->query('code');
        $state = $request->query('_s');

        if (! $code || ! $state) {
            return $this->mobileError('OAuth callback is missing required parameters.');
        }

        try {
            $supabaseData = $this->oauthService->exchangeCodeForUser($code, $state);
            $user         = $this->oauthService->findOrCreateUser($supabaseData, $provider);
        } catch (\RuntimeException $e) {
            return $this->mobileError($e->getMessage());
        }

        $token = $user->createToken('api-token')->plainTextToken;

        $scheme = config('supabase.mobile_redirect_scheme', 'vidioking');

        return redirect()->away(
            "{$scheme}://auth/callback?token=" . urlencode($token)
        );
    }

    /**
     * Redirect to the mobile deep link with an error message so the client
     * can show a meaningful UI rather than hanging on a blank browser page.
     */
    private function mobileError(string $message): RedirectResponse
    {
        $scheme = config('supabase.mobile_redirect_scheme', 'vidioking');

        return redirect()->away(
            "{$scheme}://auth/callback?error=" . urlencode($message)
        );
    }
}
