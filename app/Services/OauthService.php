<?php

namespace App\Services;

use App\Models\OauthAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Handles the full OAuth2 PKCE flow via Supabase Auth.
 *
 * Flow overview:
 *   1. buildRedirectUrl()  — generates PKCE params, stores verifier in
 *      cache, returns the Supabase /authorize URL.
 *   2. Browser follows the URL → Supabase → Google/Apple → back to our
 *      callback with ?code=xxx&_s={state_uuid}.
 *   3. exchangeCodeForUser() — exchanges code + verifier with Supabase
 *      /token?grant_type=pkce, returns Supabase user data.
 *   4. findOrCreateUser()   — resolves or auto-creates a local User and
 *      links an OauthAccount row.
 *
 * PKCE state storage:
 *   The code_verifier is stored in Laravel Cache keyed by a UUID ("state").
 *   The state UUID is embedded in the redirect_to URL as ?_s={state} so
 *   the callback can retrieve the verifier without relying on Supabase
 *   echoing back a custom state parameter.
 *
 * Supported providers: 'google', 'apple'.
 */
class OauthService
{
    private const PKCE_CACHE_TTL_MINUTES = 10;
    private const PKCE_CACHE_PREFIX      = 'oauth_pkce:';

    /** Providers wired up in Supabase dashboard. Extend when adding more. */
    public const SUPPORTED_PROVIDERS = ['google', 'apple'];

    public function isValidProvider(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED_PROVIDERS, true);
    }

    /**
     * Build the Supabase OAuth redirect URL for the given provider.
     *
     * Generates a fresh PKCE pair on every call, stores the verifier in
     * Cache (TTL = 10 min), and embeds the state UUID in the redirect_to
     * URL so the callback can look up the verifier.
     *
     * @param  string $provider    'google' or 'apple'
     * @param  string $callbackUrl The Laravel callback route URL
     * @return string              Full Supabase authorize URL to redirect to
     */
    public function buildRedirectUrl(string $provider, string $callbackUrl): string
    {
        $codeVerifier  = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        $state         = (string) Str::uuid();

        Cache::put(
            self::PKCE_CACHE_PREFIX . $state,
            $codeVerifier,
            now()->addMinutes(self::PKCE_CACHE_TTL_MINUTES)
        );

        // Embed the state UUID in redirect_to so the callback can retrieve
        // the verifier. Supabase appends ?code=xxx to redirect_to, yielding:
        // {callbackUrl}?_s={state}&code={code}
        $redirectTo = $callbackUrl . '?_s=' . $state;

        return config('supabase.url') . '/auth/v1/authorize?' . http_build_query([
            'provider'              => $provider,
            'redirect_to'           => $redirectTo,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 's256',
            'flow_type'             => 'pkce',
        ]);
    }

    /**
     * Exchange the authorization code + PKCE verifier with Supabase and
     * return the full token response including the user object.
     *
     * @throws \RuntimeException if state is expired/invalid or Supabase rejects the code
     * @return array Supabase token response: { access_token, user: { id, email, user_metadata } }
     */
    public function exchangeCodeForUser(string $code, string $state): array
    {
        // Cache::pull removes the entry atomically — the verifier is
        // single-use (replaying the same code + verifier would fail anyway
        // since Supabase invalidates codes after one use).
        $codeVerifier = Cache::pull(self::PKCE_CACHE_PREFIX . $state);

        if (! $codeVerifier) {
            throw new \RuntimeException(
                'OAuth session has expired or the link was already used. Please try signing in again.'
            );
        }

        $response = Http::withHeaders([
            'apikey'       => config('supabase.anon_key'),
            'Content-Type' => 'application/json',
        ])->post(config('supabase.url') . '/auth/v1/token?grant_type=pkce', [
            'auth_code'     => $code,
            'code_verifier' => $codeVerifier,
        ]);

        if (! $response->successful()) {
            $detail = $response->json('error_description')
                ?? $response->json('msg')
                ?? 'Unexpected error from Supabase.';

            throw new \RuntimeException('OAuth sign-in failed: ' . $detail);
        }

        return $response->json();
    }

    /**
     * Resolve a Supabase token response to a local User, creating one if
     * this is the first sign-in for this OAuth identity.
     *
     * Three paths:
     *   A. Known OAuth identity (provider + provider_id exists in
     *      oauth_accounts) → return the linked user directly.
     *   B. Unknown identity but email matches an existing user (merge) →
     *      link the oauth_account row; trust the OAuth provider's email
     *      verification and mark the local account as verified.
     *   C. Unknown identity and no existing email match (new user) →
     *      auto-create a User with role='user', email verified, no password.
     *
     * @param  array  $supabaseData Response from exchangeCodeForUser()
     * @param  string $provider     'google' or 'apple'
     * @return User
     */
    public function findOrCreateUser(array $supabaseData, string $provider): User
    {
        $supabaseUserId = $supabaseData['user']['id'];
        $email          = $supabaseData['user']['email'];
        $name           = $supabaseData['user']['user_metadata']['full_name']
            ?? $supabaseData['user']['user_metadata']['name']
            ?? Str::before($email, '@');

        // Path A: already linked.
        $oauthAccount = OauthAccount::where('provider', $provider)
            ->where('provider_id', $supabaseUserId)
            ->with('user')
            ->first();

        if ($oauthAccount) {
            return $oauthAccount->user;
        }

        // Path B: existing user with same email (merge).
        $user = User::where('email', $email)->first();

        if ($user) {
            // Trust the OAuth provider's email verification.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
        } else {
            // Path C: first-time sign-in, auto-create account.
            $user = User::create([
                'name'                => $name,
                'email'               => $email,
                'email_verified_at'   => now(),
                'must_change_password' => false,
                'password'            => null, // No password for OAuth users.
            ]);

            $user->assignRole(User::ROLE_USER);
        }

        // Link the OAuth identity regardless of which path we took.
        OauthAccount::create([
            'user_id'        => $user->id,
            'provider'       => $provider,
            'provider_id'    => $supabaseUserId,
            'provider_email' => $email,
        ]);

        return $user;
    }

    // -------------------------------------------------------------------------
    // PKCE helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a cryptographically random code verifier (base64url, 43 chars).
     * RFC 7636 §4.1 requires 43–128 chars from [A-Za-z0-9\-._~].
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Derive the S256 code challenge from the verifier.
     * challenge = BASE64URL(SHA256(ASCII(verifier)))
     */
    private function generateCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
