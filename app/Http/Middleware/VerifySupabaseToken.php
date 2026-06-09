<?php

namespace App\Http\Middleware;

use App\Services\SupabaseAuthService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySupabaseToken
{
    public function __construct(private SupabaseAuthService $supabase) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'No token provided.'], 401);
        }

        // Verify token with Supabase and get user info
        $supabaseUser = $this->supabase->verifyToken($token);

        if (!$supabaseUser || empty($supabaseUser['email'])) {
            return response()->json(['error' => 'Invalid or expired token.'], 401);
        }

        // Find the user in our DB
        $user = User::where('email', $supabaseUser['email'])->first();

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Attach user to request so controllers can access it
        $request->setUserResolver(fn() => $user);

        return $next($request);
    }
}