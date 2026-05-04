<?php

namespace App\Services;

use App\Models\PasswordResetCode;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Forgot-password / password-reset flow with 6-digit codes via email.
 * Mirrors EmailVerificationService closely — same shape (15min lifetime,
 * 5 attempts, 60s cooldown), separate state.
 *
 * Important security note:
 *   This flow can RESET an already-set password. It does NOT bypass 2FA —
 *   after reset, the user still has to pass 2FA on next login if it's
 *   enabled. The implication is that if an attacker compromises the user's
 *   email, they can reset the password but still can't reach the account
 *   without the 2FA device. That's the standard tradeoff.
 */
class PasswordResetService
{
    public const CODE_LIFETIME_MINUTES = 15;
    public const MAX_ATTEMPTS_PER_CODE = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    private const COOLDOWN_CACHE_PREFIX = 'password_reset_cooldown:';

    /**
     * Generate a fresh code, replace any existing one, dispatch the email.
     * Returns the plaintext code only for tests; production callers ignore.
     *
     * Throws \RuntimeException if the user is on cooldown.
     */
    public function sendCode(User $user): string
    {
        if ($this->onCooldown($user)) {
            throw new \RuntimeException(
                'Please wait a moment before requesting another reset code.'
            );
        }

        $code = $this->generateCode();

        PasswordResetCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES),
            ]
        );

        $this->markCooldown($user);

        $user->notify(new PasswordResetNotification($code));

        return $code;
    }

    /**
     * Verify the code AND apply the new password atomically. Returns one of:
     * 'reset', 'invalid', 'expired', 'no_code', 'too_many_attempts'.
     *
     * Combining verify+apply in a single call avoids a "verify-but-not-yet-used"
     * window where an attacker who phished the code could race the user.
     * Caller (controller) handles the password validation rules.
     */
    public function verifyAndReset(User $user, string $code, string $newPassword): string
    {
        $record = $user->passwordResetCode;

        if (! $record) {
            return 'no_code';
        }

        if ($record->isExpired()) {
            $record->delete();
            return 'expired';
        }

        if ($record->attempts >= self::MAX_ATTEMPTS_PER_CODE) {
            $record->delete();
            return 'too_many_attempts';
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            if ($record->attempts >= self::MAX_ATTEMPTS_PER_CODE) {
                $record->delete();
                return 'too_many_attempts';
            }

            return 'invalid';
        }

        // Success path. Set the new password (User has 'password' => 'hashed' cast).
        // Also clear must_change_password — the user just set their own password,
        // there's nothing to force them to change anymore.
        $user->forceFill([
            'password' => $newPassword,
            'must_change_password' => false,
        ])->save();

        $record->delete();
        Cache::forget(self::COOLDOWN_CACHE_PREFIX . $user->id);

        return 'reset';
    }

    public function onCooldown(User $user): bool
    {
        return Cache::has(self::COOLDOWN_CACHE_PREFIX . $user->id);
    }

    public function cooldownSecondsRemaining(User $user): int
    {
        $expiresAt = Cache::get(self::COOLDOWN_CACHE_PREFIX . $user->id . ':until');
        if (! $expiresAt) {
            return 0;
        }
        return max(0, $expiresAt - time());
    }

    private function markCooldown(User $user): void
    {
        $key = self::COOLDOWN_CACHE_PREFIX . $user->id;
        Cache::put($key, true, self::RESEND_COOLDOWN_SECONDS);
        Cache::put($key . ':until', time() + self::RESEND_COOLDOWN_SECONDS, self::RESEND_COOLDOWN_SECONDS);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
