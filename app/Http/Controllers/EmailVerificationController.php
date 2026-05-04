<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmailVerificationService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints for the email verification flow.
 *
 * Both endpoints are PUBLIC (no sanctum auth) because the user can't get
 * a sanctum token until they're verified. They identify themselves with
 * email + the code (or just email for resend).
 *
 * Security considerations:
 * - We do NOT reveal whether an email exists. If the email is unknown,
 *   we still return success-shaped responses to prevent enumeration.
 * - Routes are throttled tighter than normal auth (see api.php).
 * - Brute-forcing the 6-digit code is bounded by MAX_ATTEMPTS_PER_CODE
 *   in the service, which deletes the code on excess attempts.
 */
class EmailVerificationController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly EmailVerificationService $verifier,
    ) {}

    /**
     * POST /api/auth/email/verify
     * { email, code } → marks email_verified_at if the code matches.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        // Don't leak whether the email is registered. From an attacker's
        // perspective, an unknown email and a wrong code look identical.
        if (! $user) {
            return $this->error(
                errors: null,
                message: 'Invalid verification code.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success(
                data: ['already_verified' => true],
                message: 'Email is already verified.'
            );
        }

        $result = $this->verifier->verify($user, $request->input('code'));

        return match ($result) {
            'verified' => $this->success(
                data: ['verified' => true],
                message: 'Email verified successfully.'
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
                message: 'No active verification code. Please request one.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            ),
            default => $this->error(
                errors: ['code' => 'invalid'],
                message: 'Invalid verification code.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            ),
        };
    }

    /**
     * POST /api/auth/email/resend
     * { email } → sends a new code (throttled by service-level cooldown
     * AND route-level throttle middleware).
     */
    public function resend(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        // Same enumeration-prevention rationale as verify(): respond as if
        // we sent a code regardless. The route-level throttle prevents
        // attackers from using this endpoint as an "is X registered?" probe.
        if (! $user || $user->hasVerifiedEmail()) {
            return $this->success(
                data: ['cooldown_seconds' => EmailVerificationService::RESEND_COOLDOWN_SECONDS],
                message: 'If your email is registered and unverified, a code has been sent.'
            );
        }

        try {
            $this->verifier->sendCode($user);
        } catch (\RuntimeException $e) {
            return $this->error(
                errors: ['cooldown' => $this->verifier->cooldownSecondsRemaining($user)],
                message: $e->getMessage(),
                status: Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        return $this->success(
            data: ['cooldown_seconds' => EmailVerificationService::RESEND_COOLDOWN_SECONDS],
            message: 'A new verification code has been sent to your email.'
        );
    }
}
