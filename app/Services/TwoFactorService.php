<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Central service for all 2FA operations.
 *
 * Design notes:
 * - The TOTP secret is generated and stored *encrypted* the moment the user
 *   begins setup, but `two_factor_confirmed_at` stays null until they verify
 *   a code. Login is only gated by 2FA when confirmed_at is set.
 * - Recovery codes are alphanumeric, 10 chars, bcrypt-hashed inside an
 *   encrypted JSON blob. We never store them in plaintext after generation.
 * - The challenge token is a random UUID stored in the cache (default 5 min)
 *   that maps to the user_id awaiting 2FA verification. No new DB table needed.
 * - We resolve Google2FA via the 'pragmarx.google2fa' alias so we always get
 *   the Laravel-aware subclass (PragmaRX\Google2FALaravel\Google2FA), which
 *   itself extends the agnostic PHP class — so `Google2FA` typehint still works
 *   via inheritance, but the alias resolution is more explicit.
 */
class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;
    private const RECOVERY_CODE_LENGTH = 10;
    private const CHALLENGE_TTL_SECONDS = 300; // 5 minutes
    private const CHALLENGE_CACHE_PREFIX = '2fa_challenge:';

    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = app('pragmarx.google2fa');
    }

    /**
     * Generate a new TOTP secret and stage it on the user (NOT yet confirmed).
     * Returns the plaintext secret + an SVG QR code for the authenticator app.
     *
     * If the user already has an unconfirmed secret, calling this again
     * regenerates it — useful if they lost the QR before scanning.
     * If the user has already confirmed 2FA, this throws — they must
     * disable first.
     */
    public function generateSecret(User $user): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw new \RuntimeException(
                '2FA is already enabled. Disable it first to generate a new secret.'
            );
        }

        $secret = $this->google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            // Recovery codes are NOT generated yet — they're created at confirm()
            // so abandoned setups don't leave stale codes lying around.
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $otpauthUrl = $this->google2fa->getQRCodeUrl(
            config('app.name', 'Vidio King'),
            $user->email,
            $secret
        );

        return [
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
            'qr_code_svg' => $this->renderQrSvg($otpauthUrl),
        ];
    }

    /**
     * Verify a TOTP code against the user's staged secret and, if valid,
     * mark 2FA as confirmed. Generates and returns the recovery codes
     * (one-time view — caller must surface them to the user immediately).
     */
    public function confirmSetup(User $user, string $code): array
    {
        if (! $user->two_factor_secret) {
            throw new \RuntimeException('No 2FA setup in progress. Call setup first.');
        }

        if ($user->hasTwoFactorEnabled()) {
            throw new \RuntimeException('2FA is already confirmed.');
        }

        $secret = decrypt($user->two_factor_secret);

        // window=1 allows ±30s clock skew between server and client device.
        if (! $this->google2fa->verifyKey($secret, $code, 1)) {
            throw new \RuntimeException('Invalid verification code.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode(
                array_map(fn ($c) => Hash::make($c), $recoveryCodes)
            )),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }

    /**
     * Verify a TOTP code for an already-confirmed user (used at login challenge).
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        return $this->google2fa->verifyKey(
            decrypt($user->two_factor_secret),
            $code,
            1
        );
    }

    /**
     * Regenerate recovery codes (e.g., user lost them or used too many).
     * Replaces the existing set entirely; old codes become invalid.
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw new \RuntimeException('2FA is not enabled.');
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode(
                array_map(fn ($c) => Hash::make($c), $codes)
            )),
        ])->save();

        return $codes;
    }

    /**
     * Disable 2FA entirely for a user. Used both by users (with password
     * reconfirmation in the controller) and by admins (Reset 2FA action).
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Issue a short-lived challenge token after a successful password check.
     * The client posts this back along with their TOTP/recovery code to
     * exchange for a real sanctum token.
     *
     * We use the cache (not a DB table) because these are ephemeral. The
     * cache key is unguessable; expiry is short.
     */
    public function issueChallengeToken(User $user): string
    {
        $token = (string) Str::uuid();
        Cache::put(
            self::CHALLENGE_CACHE_PREFIX . $token,
            $user->id,
            self::CHALLENGE_TTL_SECONDS
        );
        return $token;
    }

    /**
     * Resolve a challenge token back to its User (or null if expired/invalid).
     * Token is consumed on success — single-use, no replay.
     */
    public function consumeChallengeToken(string $token): ?User
    {
        $cacheKey = self::CHALLENGE_CACHE_PREFIX . $token;
        $userId = Cache::pull($cacheKey);
        return $userId ? User::find($userId) : null;
    }

    /**
     * Generate the recovery code set: 8 codes × 10 alphanumeric chars.
     * Uses the URL-safe alphabet sans ambiguous characters (no 0/O, 1/I/l).
     */
    private function generateRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = '';
            for ($j = 0; $j < self::RECOVERY_CODE_LENGTH; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Render the otpauth:// URL as an inline SVG QR code.
     * SVG (not PNG) so the API response is text-only and the client can
     * inline-render it without a separate image fetch.
     */
    private function renderQrSvg(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(192),
            new SvgImageBackEnd()
        );

        return (new Writer($renderer))->writeString($otpauthUrl);
    }
}
