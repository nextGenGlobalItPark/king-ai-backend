<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

/**
 * Note on MustVerifyEmail:
 * Implementing the contract opts the model into Laravel's built-in email
 * verification helpers (hasVerifiedEmail, markEmailAsVerified, etc.) which
 * we use across the codebase. We do NOT use Laravel's bundled
 * Notifications\VerifyEmail (link-based) — we ship our own
 * VerifyEmailNotification with a 6-digit code instead.
 */
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    public const NAME_MAX_LENGTH = 255;
    public const EMAIL_MAX_LENGTH = 255;
    public const PASSWORD_MIN_LENGTH = 8;

    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
    ];

    /**
     * Hidden from serialization. The 2FA secret and recovery codes must NEVER
     * leak through API resources or model::toArray() — they're only ever
     * exposed via the dedicated setup/regenerate endpoints.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'must_change_password' => 'boolean',
            // two_factor_secret and two_factor_recovery_codes are encrypted
            // manually via Crypt:: in the TwoFactorService, NOT via cast,
            // because the recovery codes need JSON-decode after decrypt and
            // mixing the 'encrypted' cast with manual encrypt() calls causes
            // double-encryption bugs.
        ];
    }

    /**
     * The active (unconsumed) email verification code, if any.
     * The unique index on user_id guarantees at most one row.
     */
    public function emailVerificationCode(): HasOne
    {
        return $this->hasOne(EmailVerificationCode::class);
    }

    /**
     * The active (unconsumed) password reset code, if any.
     * The unique index on user_id guarantees at most one row.
     */
    public function passwordResetCode(): HasOne
    {
        return $this->hasOne(PasswordResetCode::class);
    }

    /**
     * Whether the user has fully set up and confirmed 2FA.
     * "Has a secret" alone is not enough — until they verify a code,
     * we treat 2FA as not yet active so they can't lock themselves out.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_secret)
            && ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Consume a recovery code if it matches one of the user's stored hashes.
     * Used codes are removed from the list (single-use). Returns true if a
     * code matched and was consumed.
     *
     * Recovery codes are stored as bcrypt hashes inside an encrypted JSON
     * blob, so an attacker would need both the app key AND the bcrypt to
     * brute-force them.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        if (! $this->two_factor_recovery_codes) {
            return false;
        }

        $codes = json_decode(
            decrypt($this->two_factor_recovery_codes),
            true
        ) ?: [];

        foreach ($codes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$index]);
                $this->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
                ])->save();
                return true;
            }
        }

        return false;
    }
}
