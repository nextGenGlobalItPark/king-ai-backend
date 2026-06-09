<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SupabaseAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function __construct(private SupabaseAuthService $supabase) {}

    /**
     * Redirect the user to the OAuth provider (google, github, etc.).
     * Route: GET /admin/auth/{provider}/redirect
     */
    public function redirect(string $provider)
    {
        $supported = ['google', 'github', 'facebook'];

        if (!in_array($provider, $supported)) {
            return redirect('/admin/login')
                ->withErrors(['email' => "OAuth provider [{$provider}] is not supported."]);
        }

        $pkce = $this->supabase->generatePkce();

        session([
            'pkce_code_verifier' => $pkce['verifier'],
            'oauth_provider'     => $provider,
        ]);

        $url = $this->supabase->getOAuthUrl(
            provider:      $provider,
            redirectUrl:   config('services.supabase.redirect_url'),
            codeChallenge: $pkce['challenge'],
        );

        return redirect($url);
    }

    /**
     * Handle the callback from any OAuth provider.
     * Route: GET /admin/auth/callback
     */
    public function callback(Request $request)
    {
        if ($request->query('error')) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'OAuth login was cancelled or failed.']);
        }

        $code         = $request->query('code');
        $codeVerifier = session('pkce_code_verifier');

        if (!$code || !$codeVerifier) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Authorization failed. Please try again.']);
        }

        // Exchange code for token
        $tokenData = $this->supabase->exchangeCodeForToken($code, $codeVerifier);

        if (!$tokenData || empty($tokenData['access_token'])) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Authentication failed. Please try again.']);
        }

        // Fetch user profile from Supabase
        $supabaseUser = $this->supabase->getUser($tokenData['access_token']);

        if (!$supabaseUser || empty($supabaseUser['email'])) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Could not retrieve user information.']);
        }

        // Clear PKCE session data — single use only
        session()->forget(['pkce_code_verifier', 'oauth_provider']);

        // Persist user in our DB
        $user = User::updateOrCreate(
            ['email' => $supabaseUser['email']],
            [
                'name'                 => $supabaseUser['user_metadata']['full_name']
                                         ?? $supabaseUser['user_metadata']['name']
                                         ?? $supabaseUser['email'],
                'password'             => bcrypt(Str::random(32)),
                'must_change_password' => false,
            ]
        );

        // Always ensure OAuth users are email-verified
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        // Only allow users who have been granted the admin role
        if (!$user->hasRole(config('auth.oauth_required_role', 'admin'))) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Your account does not have admin access.']);
        }

        Auth::login($user, remember: true);

        return redirect()->intended('/admin');
    }
}