<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Manages one-time tokens used in the "admin-created user must change
 * temp password on first login" flow.
 *
 * Mirrors the design of TwoFactorService::issueChallengeToken: random
 * UUID stored in the cache, single-use, short-lived. We don't use
 * sanctum tokens here because the user shouldn't have any privileges
 * yet — the change_token grants ONLY the ability to set a new password.
 */
class PasswordChangeService
{
    private const CHANGE_TTL_SECONDS = 600; // 10 minutes
    private const CHANGE_CACHE_PREFIX = 'password_change:';

    public function issueChangeToken(User $user): string
    {
        $token = (string) Str::uuid();
        Cache::put(
            self::CHANGE_CACHE_PREFIX . $token,
            $user->id,
            self::CHANGE_TTL_SECONDS
        );
        return $token;
    }

    public function consumeChangeToken(string $token): ?User
    {
        $userId = Cache::pull(self::CHANGE_CACHE_PREFIX . $token);
        return $userId ? User::find($userId) : null;
    }

    public function ttlSeconds(): int
    {
        return self::CHANGE_TTL_SECONDS;
    }

    /**
     * Generate a memorable-but-random temporary password for admin-created
     * users. Used by the Filament UserResource at user creation time.
     *
     * Format: 16 chars from a safe alphabet (no 0/O/1/I/l ambiguity).
     */
    public function generateTemporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $length = 16;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $password;
    }
}
