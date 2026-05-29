<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints for managing user 2FA.
 *
 * Setup-time endpoints (setup, confirm, regenerate-codes, disable) require
 * normal sanctum auth — the user must already be logged in.
 *
 * Login-time endpoint (challenge) does NOT require auth — it accepts the
 * challenge_token issued by /login and exchanges it for a sanctum token.
 *
 * Sensitive operations (regenerate codes, disable) require password
 * reconfirmation in the request body. We deliberately don't gate these
 * behind a separate "fresh password" middleware to keep the API stateless
 * and friendly to mobile clients that don't track password-confirmation TTL.
 */
class TwoFactorController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    /**
     * POST /api/two-factor/setup
     * Begin 2FA setup. Generates a new secret and returns the QR.
     * The secret is staged on the user record but 2FA is NOT yet active —
     * user must call /confirm with a valid code to activate.
     */
    public function setup(Request $request): JsonResponse
    {
        try {
            $data = $this->twoFactor->generateSecret($request->user());

            return $this->success(
                data: $data,
                message: 'Scan the QR code in your authenticator app, then call /two-factor/confirm with a code.',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * POST /api/two-factor/confirm
     * Confirm 2FA setup with the first code from the authenticator app.
     * Returns recovery codes — these are shown ONCE; the server only stores hashes.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $codes = $this->twoFactor->confirmSetup($request->user(), $request->input('code'));

            return $this->success(
                data: [
                    'recovery_codes' => $codes,
                    'confirmed_at' => $request->user()->two_factor_confirmed_at?->toIso8601String(),
                ],
                message: 'Two-factor authentication enabled. Save these recovery codes — they will not be shown again.',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * POST /api/two-factor/challenge
     * Exchange a challenge_token (from /login) + a TOTP code OR recovery code
     * for a real sanctum token.
     *
     * Exactly one of `code` (6-digit TOTP) or `recovery_code` (10-char alphanumeric)
     * must be provided.
     */
    public function challenge(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'min:8', 'max:20'],
        ]);

        try {
            $user = $this->twoFactor->consumeChallengeToken($request->input('challenge_token'));

            if (! $user) {
                return $this->error(
                    errors: null,
                    message: 'Invalid or expired challenge token. Please log in again.',
                    status: Response::HTTP_UNAUTHORIZED
                );
            }

            $authenticated = false;

            if ($request->filled('code')) {
                $authenticated = $this->twoFactor->verifyCode($user, $request->input('code'));
            } elseif ($request->filled('recovery_code')) {
                $authenticated = $user->consumeRecoveryCode(
                    strtoupper(trim($request->input('recovery_code')))
                );
            }

            if (! $authenticated) {
                // Re-issue a fresh challenge token so the user doesn't have to
                // restart from /login on a typo. The old token was already
                // consumed by consumeChallengeToken() above.
                $newChallenge = $this->twoFactor->issueChallengeToken($user);

                return $this->error(
                    errors: ['challenge_token' => $newChallenge],
                    message: 'Invalid two-factor code.',
                    status: Response::HTTP_UNAUTHORIZED
                );
            }

            $token = $user->createToken('api-token')->plainTextToken;

            return $this->success(
                data: ['token' => $token],
                message: 'Login successful'
            );
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * POST /api/two-factor/recovery-codes
     * Regenerate the recovery code set. Requires password reconfirmation.
     * Old codes are invalidated.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        if ($request->user()->isOauthOnly()) {
            return $this->oauthOperationDenied();
        }

        $this->confirmPassword($request);

        try {
            $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());

            return $this->success(
                data: ['recovery_codes' => $codes],
                message: 'New recovery codes generated. Save them — they will not be shown again.'
            );
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * DELETE /api/two-factor
     * Disable 2FA on the user's account. Requires password reconfirmation.
     */
    public function disable(Request $request): JsonResponse
    {
        if ($request->user()->isOauthOnly()) {
            return $this->oauthOperationDenied();
        }

        $this->confirmPassword($request);

        try {
            $this->twoFactor->disable($request->user());

            return $this->success(
                data: null,
                message: 'Two-factor authentication disabled.'
            );
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * GET /api/two-factor/status
     * Check whether 2FA is enabled for the current user. Useful for
     * mobile clients to render the right settings UI.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success(
            data: [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending_setup' => ! is_null($user->two_factor_secret) && ! $user->hasTwoFactorEnabled(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            ],
            message: 'Two-factor status retrieved'
        );
    }

    /**
     * Reconfirm the user's password — used as a soft "fresh-auth" check
     * before destructive 2FA operations. Throws ValidationException (422)
     * on mismatch.
     */
    private function confirmPassword(Request $request): void
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }
    }

    /**
     * Uniform 403 response for OAuth-only users attempting password-gated
     * operations. OAuth users have no password to confirm with.
     */
    private function oauthOperationDenied(): JsonResponse
    {
        return $this->error(
            errors: null,
            message: 'This operation is not available for accounts signed in via Google or Apple.',
            status: Response::HTTP_FORBIDDEN
        );
    }
}
