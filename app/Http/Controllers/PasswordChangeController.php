<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PasswordChangeService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forced-password-change endpoint, used after admin-created users
 * authenticate with their temporary password for the first time.
 *
 * Flow:
 *   1. User logs in with email + temp password → /auth/login.
 *   2. Login sees must_change_password = true and returns a change_token
 *      (instead of a sanctum token).
 *   3. Client posts { change_token, new_password, confirm } here.
 *   4. We swap the password, clear must_change_password, and return a
 *      real sanctum token for the user to proceed.
 */
class PasswordChangeController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly PasswordChangeService $passwordChange,
    ) {}

    /**
     * POST /api/auth/password/change-initial
     */
    public function changeInitial(Request $request): JsonResponse
    {
        $request->validate([
            'change_token' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:' . User::PASSWORD_MIN_LENGTH, 'confirmed'],
        ]);

        $user = $this->passwordChange->consumeChangeToken(
            $request->input('change_token')
        );

        if (! $user) {
            return $this->error(
                errors: null,
                message: 'Invalid or expired token. Please log in again.',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        // Defensive — even though the token came from the login flow which
        // already verified email, double-check here so this endpoint can't
        // be reached in any inconsistent state.
        if (! $user->hasVerifiedEmail()) {
            return $this->error(
                errors: null,
                message: 'Email must be verified before setting a password.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $user->forceFill([
            'password' => $request->input('new_password'), // hashed cast on User
            'must_change_password' => false,
        ])->save();

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->success(
            data: ['token' => $token],
            message: 'Password updated. You are now logged in.'
        );
    }
}
