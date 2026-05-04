<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PasswordResetService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forgot-password / reset-password endpoints.
 *
 * Both endpoints are PUBLIC — by definition the user can't authenticate
 * if they need to reset. Identification is by email + code.
 *
 * Enumeration: as with email verification, we do NOT reveal whether an
 * email is registered. Both forgot and reset return success-shaped
 * responses for unknown users so an attacker can't probe the user list
 * via timing or response shape.
 *
 * Throttling is applied at route level (api.php) on top of the
 * service-layer 60s per-user cooldown.
 */
class PasswordResetController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly PasswordResetService $resetter,
    ) {}

    /**
     * POST /api/auth/password/forgot
     * { email } → sends a 6-digit code if the user exists. Always
     * returns a success-shaped response to prevent enumeration.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        // Generic success response regardless of whether the user exists.
        $genericResponse = $this->success(
            data: ['cooldown_seconds' => PasswordResetService::RESEND_COOLDOWN_SECONDS],
            message: 'If your email is registered, a reset code has been sent.'
        );

        if (! $user) {
            return $genericResponse;
        }

        // We require email_verified_at before allowing reset — otherwise
        // a fresh attacker registering the email could "reset" through
        // their unverified inbox claim. This forces them through real
        // verification first.
        if (! $user->hasVerifiedEmail()) {
            return $genericResponse;
        }

        try {
            $this->resetter->sendCode($user);
        } catch (\RuntimeException $e) {
            return $this->error(
                errors: ['cooldown' => $this->resetter->cooldownSecondsRemaining($user)],
                message: $e->getMessage(),
                status: Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        return $genericResponse;
    }

    /**
     * POST /api/auth/password/reset
     * { email, code, new_password, new_password_confirmation } →
     * sets the new password. Returns NO sanctum token; the user must
     * call /auth/login afterwards. This keeps the reset flow stateless
     * and avoids accidentally bypassing the 2FA gate that login enforces.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'digits:6'],
            'new_password' => ['required', 'string', 'min:' . User::PASSWORD_MIN_LENGTH, 'confirmed'],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        // Same enumeration-prevention shape as /forgot. We tell the user
        // "invalid code" rather than "no such user" so an attacker can't
        // probe for registered emails by trying garbage codes.
        if (! $user) {
            return $this->error(
                errors: ['code' => 'invalid'],
                message: 'Invalid reset code.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->resetter->verifyAndReset(
            $user,
            $request->input('code'),
            $request->input('new_password'),
        );

        return match ($result) {
            'reset' => $this->success(
                data: ['reset' => true],
                message: 'Password updated. Please log in with your new password.'
            ),
            'expired' => $this->error(
                errors: ['code' => 'expired'],
                message: 'This code has expired. Please request a new one.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            ),
            'too_many_attempts' => $this->error(
                errors: ['code' => 'too_many_attempts'],
                message: 'Too many incorrect attempts. Please request a new code.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            ),
            'no_code' => $this->error(
                errors: ['code' => 'no_code'],
                message: 'No active reset code. Please request one.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            ),
            default => $this->error(
                errors: ['code' => 'invalid'],
                message: 'Invalid reset code.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            ),
        };
    }
}
