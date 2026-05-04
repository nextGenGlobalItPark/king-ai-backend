<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserService
{
    public function __construct(
        private readonly EmailVerificationService $emailVerifier,
        private readonly PasswordChangeService $passwordChange,
    ) {}

    /**
     * Reusable method to create a user with a hashed plaintext password
     * AND assign a role (defaulting to 'user').
     *
     * Used by the public /auth/register flow where the user provides their
     * own password — they always get the 'user' role; admin role assignment
     * is a separate admin-only action via the Filament UI or the admin API.
     *
     * @param array $data       name, email, password (plaintext)
     * @param string $role      role name to assign; defaults to User::ROLE_USER
     */
    public function createUser(array $data, string $role = User::ROLE_USER): User
    {
        return DB::transaction(function () use ($data, $role) {
            $data['password'] = Hash::make($data['password']);
            $user = User::create($data);
            $user->assignRole($role);
            return $user;
        });
    }

    /**
     * Single source of truth for "admin creates a user with a temporary
     * password". Used by:
     *   - Filament CreateUser page
     *   - UserController::store (admin API)
     *
     * Behavior:
     *   1. Generate a 16-char temp password (returned plaintext for one-time
     *      display to the admin).
     *   2. Persist the user with must_change_password = true and
     *      email_verified_at = null so the verification flow runs on first
     *      login.
     *   3. Assign a role (defaulting to 'user'). The admin can override via
     *      $role to provision another admin.
     *   4. Send TWO emails (separately): the verification code and the
     *      temp password. Best-effort — failures are logged but do not
     *      block user creation.
     *
     * Returns the [User, plaintextTempPassword] tuple. Callers MUST surface
     * the plaintext password to the admin once and then forget it; we never
     * store it anywhere.
     */
    public function createUserWithTemporaryPassword(array $data, string $role = User::ROLE_USER): array
    {
        return DB::transaction(function () use ($data, $role) {
            $tempPassword = $this->passwordChange->generateTemporaryPassword();

            // The 'password' cast on User hashes the plaintext automatically.
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $tempPassword,
                'must_change_password' => true,
            ]);

            $user->assignRole($role);

            $this->dispatchOnboardingEmails($user, $tempPassword);

            return [$user, $tempPassword];
        });
    }

    /**
     * Issue a fresh temporary password to an existing user. Used when the
     * original temp-password email got lost / never arrived.
     *
     * Caller (admin context) must guard against calling this on a user who
     * has already set their own password — otherwise we'd be unilaterally
     * locking that user out. We enforce that here as well as in the UI.
     *
     * Returns the plaintext temp password for one-time display to the admin.
     */
    public function regenerateTemporaryPassword(User $user): string
    {
        if (! $user->must_change_password) {
            throw new \RuntimeException(
                'Cannot regenerate a temporary password for a user who has '
                . 'already set their own. Use the password reset flow instead.'
            );
        }

        $tempPassword = $this->passwordChange->generateTemporaryPassword();

        $user->forceFill([
            'password' => $tempPassword, // hashed cast
            'must_change_password' => true, // already true, but defensively re-set
        ])->save();

        // Send the temp password email. We deliberately do NOT auto-resend
        // the verification code here — the admin can use the dedicated
        // "Resend verification" action if needed, and forcing two emails
        // for every regenerate would be noisy.
        try {
            $user->notify(new TemporaryPasswordNotification($tempPassword));
        } catch (\Throwable $mailError) {
            Log::warning('Failed to send temp password on regenerate', [
                'user_id' => $user->id,
                'error' => $mailError->getMessage(),
            ]);
        }

        return $tempPassword;
    }

    /**
     * Send the two separate onboarding emails. Best-effort — each is
     * wrapped in its own try/catch so a failure of one doesn't suppress
     * the other.
     */
    private function dispatchOnboardingEmails(User $user, string $tempPassword): void
    {
        try {
            $this->emailVerifier->sendCode($user);
        } catch (\Throwable $mailError) {
            Log::warning('Failed to send verification email', [
                'user_id' => $user->id,
                'error' => $mailError->getMessage(),
            ]);
        }

        try {
            $user->notify(new TemporaryPasswordNotification($tempPassword));
        } catch (\Throwable $mailError) {
            Log::warning('Failed to send temporary password email', [
                'user_id' => $user->id,
                'error' => $mailError->getMessage(),
            ]);
        }
    }
}