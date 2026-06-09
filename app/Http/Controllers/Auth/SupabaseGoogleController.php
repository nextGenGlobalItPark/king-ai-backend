<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SupabaseAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SupabaseGoogleController extends Controller
{
    public function __construct(private SupabaseAuthService $supabase) {}

    public function redirect()
    {
        $pkce = $this->supabase->generatePkce();

        session(['pkce_code_verifier' => $pkce['verifier']]);

        $url = $this->supabase->getOAuthUrl(
            provider:      'google',
            redirectUrl:   config('services.supabase.redirect_url'),
            codeChallenge: $pkce['challenge'],
        );

        return redirect($url);
    }

    public function callback(Request $request)
    {
        if ($request->query('error')) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Google login was cancelled or failed.']);
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
                ->withErrors(['email' => 'Google authentication failed. Please try again.']);
        }

        // Fetch user profile
        $supabaseUser = $this->supabase->getUser($tokenData['access_token']);

        if (!$supabaseUser || empty($supabaseUser['email'])) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Could not retrieve user information.']);
        }

        session()->forget('pkce_code_verifier');

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

        // Always ensure Google users are email-verified
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        if (!$user->hasRole('admin')) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Your account does not have admin access.']);
        }

        // Start Laravel session for the user
        Auth::login($user, remember: true);

        return redirect()->intended('/admin');
    }
}