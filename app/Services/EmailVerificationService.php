<?php

namespace App\Services;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Central service for email verification with 6-digit codes.
 *
 * Configuration (hard-coded; promoted to config later if it becomes
 * worth tuning per-deploy):
 *   - Code: 6 digits (000000–999999)
 *   - Lifetime: 15 minutes
 *   - Max attempts per code: 5 — after that the code is invalidated
 *     and the user must request a new one.
 *   - Resend cooldown: 60 seconds — uses the cache as a per-user lock
 *     rather than a DB column, since it's ephemeral.
 *
 * Why hash the code:
 *   The code is short (~20 bits of entropy) so DB read access could
 *   reveal an in-flight code. We bcrypt it; the user supplies the
 *   plaintext on verify and we Hash::check.
 */
class EmailVerificationService
{
    public const CODE_LIFETIME_MINUTES = 15;
    public const MAX_ATTEMPTS_PER_CODE = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    private const RESEND_COOLDOWN_CACHE_PREFIX = 'email_verify_cooldown:';

    /**
     * Generate a fresh code, store it (replacing any existing code for this
     * user), and dispatch the email. Returns the plaintext code only for
     * test/debugging needs — production callers ignore it.
     *
     * Throws \RuntimeException if the user is on resend cooldown.
     */
    public function sendCode(User $user): string
    {
        if ($this->onCooldown($user)) {
            throw new \RuntimeException(
                'Please wait a moment before requesting another code.'
            );
        }

        $code = $this->generateCode();

        // Replace any existing code for this user. The unique index on
        // user_id means updateOrCreate cleanly supersedes old codes —
        // important: a leaked old code stops working as soon as a new one
        // is requested.
        EmailVerificationCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES),
            ]
        );

        // Set cooldown BEFORE sending — if the mail fails we still want the
        // cooldown so the user isn't spammed with retries on a flaky SMTP.
        $this->markCooldown($user);

        $user->notify(new VerifyEmailNotification($code));

        return $code;
    }

    /**
     * Verify a code submitted by the user. On success: marks
     * email_verified_at and deletes the code row. On failure: increments
     * the attempt counter; once max attempts is hit, deletes the code so
     * the user must request a new one.
     *
     * Returns one of: 'verified', 'invalid', 'expired', 'no_code',
     * 'too_many_attempts'.
     */
    public function verify(User $user, string $code): string
    {
        $record = $user->emailVerificationCode;

        if (! $record) {
            return 'no_code';
        }

        if ($record->isExpired()) {
            $record->delete();
            return 'expired';
        }

        if ($record->attempts >= self::MAX_ATTEMPTS_PER_CODE) {
            // Defensive — shouldn't normally hit this since we delete on max,
            // but if a race left the row in place, treat it as invalidated.
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

        // Success path.
        $user->forceFill(['email_verified_at' => now()])->save();
        $record->delete();

        // Clear any lingering cooldown so a follow-up resend (if e.g. the
        // user re-installs the app) isn't unnecessarily blocked.
        Cache::forget(self::RESEND_COOLDOWN_CACHE_PREFIX . $user->id);

        return 'verified';
    }

    /**
     * Whether the user is currently within the resend cooldown window.
     */
    public function onCooldown(User $user): bool
    {
        return Cache::has(self::RESEND_COOLDOWN_CACHE_PREFIX . $user->id);
    }

    /**
     * Seconds remaining on the cooldown, or 0 if not on cooldown.
     * Useful for the API response so the client can display a countdown.
     */
    public function cooldownSecondsRemaining(User $user): int
    {
        $expiresAt = Cache::get(
            self::RESEND_COOLDOWN_CACHE_PREFIX . $user->id . ':until'
        );

        if (! $expiresAt) {
            return 0;
        }

        return max(0, $expiresAt - time());
    }

    private function markCooldown(User $user): void
    {
        $key = self::RESEND_COOLDOWN_CACHE_PREFIX . $user->id;
        $untilKey = $key . ':until';
        Cache::put($key, true, self::RESEND_COOLDOWN_SECONDS);
        Cache::put($untilKey, time() + self::RESEND_COOLDOWN_SECONDS, self::RESEND_COOLDOWN_SECONDS);
    }

    /**
     * Generate a 6-digit code with leading zeros, using
     * cryptographically-secure randomness (random_int).
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
