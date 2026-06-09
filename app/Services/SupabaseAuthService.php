<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SupabaseAuthService
{
    private string $url;
    private string $serviceKey;
    private string $anonKey;

    public function __construct()
    {
        $this->url        = config('services.supabase.url');
        $this->serviceKey = config('services.supabase.service_key');
        $this->anonKey    = config('services.supabase.anon_key');
    }

    /**
     * Generate PKCE code verifier and challenge pair.
     */
    public function generatePkce(): array
    {
        $codeVerifier  = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(
            strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'),
            '='
        );

        return [
            'verifier'  => $codeVerifier,
            'challenge' => $codeChallenge,
        ];
    }

    /**
     * Build the Supabase OAuth redirect URL for a given provider.
     */
    public function getOAuthUrl(string $provider, string $redirectUrl, string $codeChallenge): string
    {
        return $this->url . '/auth/v1/authorize?' . http_build_query([
            'provider'              => $provider,
            'redirect_to'           => $redirectUrl,
            'flow_type'             => 'pkce',
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Exchange an authorization code + PKCE verifier for an access token.
     * Returns the full token response array, or null on failure.
     */
    public function exchangeCodeForToken(string $code, string $codeVerifier): ?array
    {
        $response = Http::withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'Content-Type'  => 'application/json',
        ])->post($this->url . '/auth/v1/token?grant_type=pkce', [
            'auth_code'     => $code,
            'code_verifier' => $codeVerifier,
        ]);

        if (!$response->successful()) {
            logger()->error('Supabase token exchange failed', $response->json());
            return null;
        }

        return $response->json();
    }

    /**
     * Fetch the authenticated user's profile using an access token.
     * Returns the user array, or null on failure.
     */
    public function getUser(string $accessToken): ?array
    {
        $response = Http::withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get($this->url . '/auth/v1/user');

        if (!$response->successful()) {
            logger()->error('Supabase get user failed', $response->json());
            return null;
        }

        return $response->json();
    }

    /**
     * Verify a Supabase JWT access token.
     * Returns the user array if valid, or null if invalid/expired.
     */
    public function verifyToken(string $accessToken): ?array
    {
        return $this->getUser($accessToken);
    }
}