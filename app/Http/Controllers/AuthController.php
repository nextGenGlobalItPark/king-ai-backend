<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceRegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Device;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\PasswordChangeService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly UserService $userService,
        private readonly TwoFactorService $twoFactor,
        private readonly EmailVerificationService $emailVerifier,
        private readonly PasswordChangeService $passwordChange,
    ) {}

    /**
     * Public registration. Creates the user, sends a verification code,
     * and returns 201 with `requires_email_verification: true`. We do NOT
     * issue a sanctum token here — the client must verify the code first
     * (via /auth/email/verify) and then call /auth/login to get a token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $user = $this->userService->createUser($request->validated());
            DB::commit();

            // Best-effort send. If SMTP is misconfigured we still want
            // registration to succeed — the user can hit /resend later.
            try {
                $this->emailVerifier->sendCode($user);
            } catch (\Throwable $mailError) {
                Log::warning('Failed to send verification email at registration', [
                    'user_id' => $user->id,
                    'error' => $mailError->getMessage(),
                ]);
            }

            return $this->success(
                data: [
                    'user' => new UserResource($user),
                    'requires_email_verification' => true,
                    'email' => $user->email,
                ],
                message: 'Registered. Check your email for a 6-digit verification code.',
                status: Response::HTTP_CREATED
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * Login flow (in priority order):
     *   1. Bad credentials                    → 401.
     *   2. Email not verified                 → 403, requires_email_verification = true.
     *      We DO send a fresh code here (subject to cooldown) so the user
     *      can recover even if their original code expired.
     *   3. must_change_password is true       → 200, requires_password_change + change_token.
     *   4. 2FA enabled                        → 200, requires_two_factor + challenge_token.
     *   5. Otherwise                          → 200, sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return $this->error(null, 'Invalid credentials', Response::HTTP_UNAUTHORIZED);
            }

            // Step 2: email must be verified before anything else.
            if (! $user->hasVerifiedEmail()) {
                // Trigger a fresh code unless the user's already on cooldown.
                if (! $this->emailVerifier->onCooldown($user)) {
                    try {
                        $this->emailVerifier->sendCode($user);
                    } catch (\Throwable $mailError) {
                        Log::warning('Failed to send verification email on login', [
                            'user_id' => $user->id,
                            'error' => $mailError->getMessage(),
                        ]);
                    }
                }

                return $this->error(
                    errors: [
                        'requires_email_verification' => true,
                        'email' => $user->email,
                    ],
                    message: 'Please verify your email address before logging in.',
                    status: Response::HTTP_FORBIDDEN
                );
            }

            // Step 3: temp-password change required.
            if ($user->must_change_password) {
                $changeToken = $this->passwordChange->issueChangeToken($user);

                return $this->success(
                    data: [
                        'requires_password_change' => true,
                        'change_token' => $changeToken,
                        'expires_in' => $this->passwordChange->ttlSeconds(),
                    ],
                    message: 'Please set a new password to continue.'
                );
            }

            // Step 4: 2FA gate.
            if ($user->hasTwoFactorEnabled()) {
                $challengeToken = $this->twoFactor->issueChallengeToken($user);

                return $this->success(
                    data: [
                        'requires_two_factor' => true,
                        'challenge_token' => $challengeToken,
                        'expires_in' => 300,
                    ],
                    message: 'Two-factor authentication required'
                );
            }

            // Step 5: clean login.
            $token = $user->createToken('api-token')->plainTextToken;
            return $this->success(['token' => $token], 'Login successful');

        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();
            return $this->success(null, 'Logged out successfully');
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    public function registerDevice(DeviceRegisterRequest $request)
    {
        try {
            DB::beginTransaction();
            Device::updateOrCreate(
                ['user_id' => auth()->id(), 'token' => $request->token],
                [
                    'type' => $request->type,
                    'platform' => $request->platform,
                    'is_active' => true,
                ]
            );
            DB::commit();
            return $this->success(message: 'Device registered successfully', status: Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(errors: null, message: $e->getMessage());
        }
    }
}
