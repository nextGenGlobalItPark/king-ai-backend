# Triage Fixes — April/May 2026

This document covers two batches of changes shipped together:

1. **Triage fixes** — bug fixes and cleanups (sections 1–11 below).
2. **Two-factor authentication** — new feature (section "2FA" below).

---

## 2FA — Authenticator-app two-factor authentication

Optional, opt-in 2FA via TOTP (Google Authenticator, Authy, 1Password, etc.)
with bcrypt-hashed recovery codes. Available to both API users and Filament
admins. Admins can reset 2FA for any user from the Users table.

### Dependencies added (`composer.json`)

```
"bacon/bacon-qr-code": "^3.0",
"pragmarx/google2fa-laravel": "^2.3"
```

After deploy: `composer install` on the server.

### Schema (new migration `2026_05_04_000001_add_two_factor_columns_to_users_table`)

Adds three nullable columns to `users`:

- `two_factor_secret` (text, encrypted) — TOTP secret. Populated at setup,
  null when 2FA is off.
- `two_factor_recovery_codes` (text, encrypted) — JSON array of bcrypt
  hashes of one-time recovery codes.
- `two_factor_confirmed_at` (timestamp) — set only after the user verifies
  their first TOTP code. Until this is non-null, 2FA is considered NOT
  active (so an abandoned setup can't lock anyone out).

Run after deploy: `php artisan migrate`.

### API contract

All endpoints return the standard `{ success, data, message, errors }`
envelope.

**Login flow** (modified):

`POST /api/auth/login` now branches on whether the user has confirmed 2FA:

- Without 2FA: returns `{ token: "..." }` as before.
- With 2FA: returns
  `{ requires_two_factor: true, challenge_token: "<uuid>", expires_in: 300 }`
  instead of a token. The client must call `/two-factor/challenge` within
  5 minutes to exchange that token for a sanctum token.

**New endpoints:**

| Method   | Path                              | Auth      | Purpose |
|----------|-----------------------------------|-----------|---------|
| POST     | `/api/two-factor/challenge`       | none      | Exchange `challenge_token` + (`code` OR `recovery_code`) for sanctum token. Throttled 10/min. |
| GET      | `/api/two-factor/status`          | sanctum   | `{ enabled, pending_setup, confirmed_at }` |
| POST     | `/api/two-factor/setup`           | sanctum   | Begin setup. Returns `{ secret, otpauth_url, qr_code_svg }`. |
| POST     | `/api/two-factor/confirm`         | sanctum   | Finalize setup with first code. Returns recovery codes (one-time view). |
| POST     | `/api/two-factor/recovery-codes`  | sanctum + password | Regenerate recovery codes. Old codes invalidated. |
| DELETE   | `/api/two-factor`                 | sanctum + password | Disable 2FA entirely. |

The challenge endpoint accepts either a 6-digit `code` (TOTP) or a 10-char
alphanumeric `recovery_code`. On wrong code, the response includes a fresh
`challenge_token` in `errors.challenge_token` so the user doesn't have to
re-enter their password.

### Filament admin

- New "Two-factor authentication" page in the user menu (top-right). Shows
  the appropriate UI for the current state: disabled / pending / enabled.
  Setup, confirm, regenerate codes, disable — all in one place.
- New `EnsureTwoFactorChallenge` middleware sits in the `authMiddleware`
  pipeline after `Authenticate`. If the logged-in admin has 2FA enabled
  but hasn't passed the challenge in the current session, they're
  redirected to a hidden `TwoFactorChallenge` page. The session flag is
  scoped to the session and cleared on logout.
- New "2FA" indicator column on the Users table (green shield = enabled).
- New "2FA enabled" filter on Users.
- New **"Reset 2FA"** row action on Users — admin-only; visible only when
  the target user has 2FA enabled. Wipes the secret/codes/confirmed_at and
  logs the action with admin id, admin email, target id, and target email
  to `storage/logs/laravel.log` (`Log::warning`). Used when a user has
  lost both their authenticator AND their recovery codes.

### Security notes

- The TOTP secret is stored encrypted via `Crypt` (Laravel app key). It's
  hidden from `$user->toArray()` and any API resource by being in the
  `$hidden` model array.
- Recovery codes are hashed (bcrypt) AND the JSON array is encrypted —
  so an attacker would need both the app key and bcrypt brute-force to
  recover plaintext codes.
- The challenge token is a random UUID with a 5-minute TTL stored in the
  Laravel cache. It's single-use (`Cache::pull`).
- TOTP verification uses `window=1` (±30s clock skew tolerance).
- Disabling 2FA and regenerating recovery codes both require password
  reconfirmation in the request body — soft "fresh-auth" without needing
  a separate password-confirmation TTL endpoint.
- All 2FA endpoints inherit the existing auth throttle; the challenge
  endpoint has an additional 10/min limit to slow brute-force on the OTP.

### Things NOT done (intentional, can be follow-ups)

- 2FA is **optional** per the spec — there is no "require 2FA for admins"
  toggle. If you ever want to enforce it, the cleanest spot is to add a
  check in `EnsureTwoFactorChallenge`: if the user is an admin and
  `! $user->hasTwoFactorEnabled()`, redirect them to `TwoFactorSettings`
  to set it up.
- No "trusted device" flow — every new browser session re-challenges.
- No SMS/email fallback — TOTP only.
- No audit trail of OTP attempts (just the admin reset action). If you
  need this, log inside `TwoFactorService::verifyCode`.

---

## Triage fixes (shipped in the same batch)

### 1. Schedule wiring (Laravel 12)

`bootstrap/app.php` — added `->withSchedule(...)` registering the
`generations:cleanup` command (daily at 03:00, with `withoutOverlapping()` and
`onOneServer()`).

Why: Laravel 12 ignores `app/Console/Kernel.php`. The schedule defined there
was never running. Deleted the dead `app/Console/Kernel.php` and
`app/Http/Kernel.php` to prevent confusion. (Vidu imports were intentionally
NOT scheduled — those commands remain as orphaned files; remove on a future
Vidu cleanup pass.)

Also updated `Deployment.md` and `Architecture.md` to reflect the new
schedule location and added a note that the VPS cron must invoke
`php artisan schedule:run` every minute for any of this to fire.

### 2. TemplateResource metadata never resolved

`app/Http/Resources/TemplateResource.php` — `whenNotNull($value, $closure)`
was being called like `when($value, $closure)`, but `whenNotNull`'s second
arg is a default value, not a callback. Result: when `metadata` was non-null,
the closure was discarded and `resolveMetadata()` never ran — so
`sample_content_index` and `preview_video_content_index` were returned to
the mobile client as raw indexes instead of resolved URLs.

Switched to `$this->when(! is_null($this->metadata), fn () => $this->resolveMetadata(...))`.

### 3. /api/status race condition

`app/Http/Controllers/AiController.php::status()` — replaced `firstOrFail()`
with a soft `first()` lookup. When the AiJob row hasn't been written yet
(race between `/generate` returning and the queue worker creating the row),
the endpoint now returns a synthetic `{"status": "queued"}` response so the
client can keep polling without seeing a misleading 404.

### 4. Polling backoff broken under Carbon 3

`app/Jobs/AiPollingJob.php` — Carbon 3 changed `diffInSeconds()` to return
**signed** values by default. `now()->diffInSeconds($pastDate)` returns a
negative integer; the existing `max(0, ...)` clamped it to 0 every time, so
the backoff interval index stayed at 0 and `[10, 20, 40, 60]` collapsed to
"always poll every 10s". Fixed by passing `true` as the second argument
(absolute) to `diffInSeconds`.

### 5. FCM token pruning unreliable

`app/Services/FCMPushService.php` — `$failure->target()` returns a
`MessageTarget` object in `kreait/firebase-php` v7+, not a string. Cast
explicitly to string before logging and using in the WHERE clause so the
`devices` row gets reliably deactivated regardless of PDO driver coercion.

### 6. Upload endpoint adds absolute URL (additive-only)

`app/Http/Controllers/UploadController.php::upload()` — response now
includes a new `url` field with the absolute URL via
`Storage::disk('public')->url(...)`. The original `path` field is
unchanged (still returns `'storage/templates/<uuid>.jpg'`) so the mobile
client keeps working without coordination. New clients can consume `url`
directly instead of concatenating `APP_URL + '/' + path`.

Response shape:
```json
{
  "data": {
    "path": "storage/templates/<uuid>.jpg",  // unchanged, for old clients
    "url":  "https://king-ai.../storage/templates/<uuid>.jpg"  // NEW
  }
}
```

### 7. Float fields no longer truncated

`app/Services/AiGenerationService.php::buildFromTemplate()` — `'number'`
type was casting through `(int)` along with `'integer'`, silently
truncating decimal values like `5.5` to `5`. Split into `'integer' => (int)`,
`'number'/'float' => (float)`.

### 8. Download URL index uses foreach key

`app/Http/Controllers/HistoryController.php::formatHistoryItem()` —
replaced `array_search($asset, $assets)` with the foreach `$index`. The
old code did a loose comparison across all asset arrays (slow + brittle —
duplicate-shaped assets would clash); the index is already in scope.

### 10. Removed legacy poll strategies

Deleted `app/Strategies/KlingPollStrategy.php` and `ViduPollStrategy.php`.
`PollStrategyResolver` always returns `GenericPollStrategy`; the legacy
classes hadn't been resolved since the generic refactor.

### 11. Removed stale `extra_params` cast

`app/Models/AiFeatureConfig.php` — dropped the `'extra_params' => 'array'`
cast. The column was removed in migration
`2025_12_06_142205_remove-param_mapping-default_params-extra_params-...`.

## Known issues NOT addressed in this round

These are flagged for awareness; they didn't make it into this PR to keep
scope tight. Triage as you see fit.

- `app/Http/Requests/StoreAiFeatureConfigRequest.php` and
  `UpdateAiFeatureConfigRequest.php` are unreferenced dead code (the routes
  use the type-specific Static-Token / JWT request classes). They also still
  validate columns that no longer exist (`param_mapping`, `default_params`,
  `extra_params`). Safe to delete on a separate cleanup pass.
- `app/Console/Commands/ViduTemplateImport.php` references a `ViduTemplate`
  model that doesn't exist — would crash on invocation. Left in place pending
  the broader Vidu deprecation.
- `INSTRUCTIONS.md` is stale (talks about `/generate/text-summary`, doesn't
  mention webhooks/history/templates). `Architecture.md` is the source of
  truth.
- All models still use `protected $guarded = ['']` (full mass-assignment
  open). This is the previously-deferred decision and untouched here.
