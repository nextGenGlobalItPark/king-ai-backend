# Vidio King — Feature Documentation

**Version:** May 2026
**Stack:** Laravel 12 · PHP 8.2 · Filament 3.3 · MySQL · Sanctum · Spatie Permissions
**Deploy:** VPS (Nginx + PHP-FPM) · PM2 queue workers · Firebase FCM · Gmail SMTP

This document describes every feature in the system, how they fit together, and how to operate them. It covers both the original platform and the auth/onboarding/permissions work shipped in May 2026.

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Authentication & Onboarding](#2-authentication--onboarding)
    - 2.1 [Public Registration](#21-public-registration)
    - 2.2 [Email Verification](#22-email-verification)
    - 2.3 [Login Flow](#23-login-flow)
    - 2.4 [Forgot Password](#24-forgot-password)
    - 2.5 [Two-Factor Authentication](#25-two-factor-authentication)
    - 2.6 [Admin-Created Users & Temp Passwords](#26-admin-created-users--temp-passwords)
    - 2.7 [OAuth2 — Google & Apple Sign-In](#27-oauth2--google--apple-sign-in)
3. [Authorization — Roles & Permissions](#3-authorization--roles--permissions)
4. [AI Generation Pipeline](#4-ai-generation-pipeline)
    - 4.1 [Vendor Abstraction](#41-vendor-abstraction)
    - 4.2 [Generation Request Lifecycle](#42-generation-request-lifecycle)
    - 4.3 [Polling vs. Webhooks](#43-polling-vs-webhooks)
    - 4.4 [Asset Permanence](#44-asset-permanence)
5. [Catalog Management](#5-catalog-management)
    - 5.1 [Data Sources](#51-data-sources)
    - 5.2 [AI Features](#52-ai-features)
    - 5.3 [AI Feature Configs](#53-ai-feature-configs)
    - 5.4 [Categories](#54-categories)
    - 5.5 [Templates & Template Contents](#55-templates--template-contents)
    - 5.6 [Vendor Status Mappings](#56-vendor-status-mappings)
6. [Generation History](#6-generation-history)
7. [File Uploads](#7-file-uploads)
8. [Push Notifications](#8-push-notifications)
9. [Admin Panel (Filament)](#9-admin-panel-filament)
10. [Operational Tooling](#10-operational-tooling)
11. [Email Infrastructure](#11-email-infrastructure)
12. [Security Posture](#12-security-posture)
13. [Configuration Reference](#13-configuration-reference)
14. [Glossary](#14-glossary)

---

## 1. System Overview

Vidio King is a Laravel-based middleware API that sits between mobile/web clients and external AI video generation vendors (currently Kling and Vidu). It provides:

- A unified API surface for clients regardless of which underlying vendor handles the job
- Authentication, onboarding, and 2FA
- A configuration-driven vendor integration layer (no code changes to onboard a new vendor — only admin panel config)
- Async generation via Laravel queues
- Asset download to local storage so push notifications reference permanent URLs
- A Filament admin panel for catalog management and operations
- FCM push notifications when generations complete

### Architecture at a glance

```
Mobile/Web client
      │
      ▼
   Sanctum auth + Permission middleware
      │
      ▼
   AiController / HistoryController / etc.
      │
      ▼
   Laravel Queue (AiGenerationJob)
      │
      ▼
   GenericPollStrategy → Vendor API (Kling/Vidu)
      │
      ▼
   AssetDownloadJob (permanent local storage)
      │
      ▼
   Firebase FCM push to user's device
```

---

## 2. Authentication & Onboarding

The system supports two parallel auth surfaces:

- **API** (Laravel Sanctum): bearer tokens, used by mobile/web clients
- **Filament admin panel**: session-based, used by admins via the web UI

Both surfaces share the same underlying `User` model and the same authentication flow rules.

### 2.1 Public Registration

**Endpoint:** `POST /api/auth/register`

**Request body:**
```json
{
  "name": "Saifur Rahman",
  "email": "saifur@example.com",
  "password": "secret123",
  "password_confirmation": "secret123"
}
```

**Behavior:**
1. Validates input (email unique, password ≥ 8 chars, password_confirmation matches)
2. Creates the user with `email_verified_at = null` and assigns the `user` role
3. Sends a 6-digit verification code via email (queued)
4. Returns `201 Created` with the user object and a flag indicating verification is required

**Response:**
```json
{
  "success": true,
  "data": {
    "user": { "id": 42, "name": "...", "email": "...", "roles": ["user"] },
    "requires_email_verification": true,
    "email": "saifur@example.com"
  },
  "message": "Account created. Check your email for the verification code."
}
```

> **Important:** Registration does NOT return a Sanctum token. The client must verify the email first, then call `/auth/login` to obtain a token. This is a deliberate change made in May 2026 to enforce email ownership before issuing credentials.

### 2.2 Email Verification

The same 6-digit code mechanism is used everywhere a code is needed (registration, login on unverified account, manual resend).

**Endpoints:**
- `POST /api/auth/email/verify` — `{email, code}` → marks `email_verified_at = now()`
- `POST /api/auth/email/resend` — `{email}` → issues a new code (60 second cooldown)

**Code properties:**
- 6 digits, numeric (mobile-keypad-friendly)
- 15-minute expiry
- Maximum 5 verification attempts per code; on the 5th failure the code is invalidated and the user must request a new one
- 60-second cooldown between resend requests (rate-limit by email address)
- Stored in the `email_verification_codes` table with a unique index on `user_id` (only one active code per user; new requests overwrite)

**Filament admin panel** has its own `/admin/email-verify` page with the same UX. When an unverified admin attempts to access the panel, the `EnsureEmailVerified` middleware redirects them there.

### 2.3 Login Flow

**Endpoint:** `POST /api/auth/login`

The login flow is gated in a strict priority order. If a check fails, that check's response is returned immediately and lower-priority checks are not reached.

| Priority | Check | Response on failure |
|---|---|---|
| 1 | Credentials valid? | 401 `Invalid credentials` |
| 2 | Email verified? | 403 `requires_email_verification: true` (and a fresh code is auto-sent) |
| 3 | Must change password? | 200 with `change_token` and `requires_password_change: true` |
| 4 | 2FA enabled? | 200 with `challenge_token` and `requires_two_factor: true` |
| 5 | All passed | 200 with Sanctum bearer `token` |

This priority ordering prevents enumeration attacks (an attacker can't tell whether a wrong password is for an unverified or fully-active account) while still delivering an actionable next step to legitimate clients.

The Filament admin login follows the same priority order through the chain of `EnsureEmailVerified` → `EnsureMustChangePassword` → `EnsureTwoFactorChallenge` middlewares.

### 2.4 Forgot Password

**Endpoints:**
- `POST /api/auth/password/forgot` — `{email}` → sends a 6-digit reset code
- `POST /api/auth/password/reset` — `{email, code, password, password_confirmation}` → resets the password

**Behavior:**
- Same 6-digit code UX as email verification (15min expiry, max 5 attempts, 60s resend cooldown)
- Stored in a separate `password_reset_codes` table (so a user can have an active verification code AND a reset code at the same time)
- Resetting the password also clears the `must_change_password` flag if it was set
- Reset does NOT issue a Sanctum token. The user must log in afterward, which preserves the 2FA gate.

**Filament:** `/admin/forgot-password` page (custom SimplePage) is wired into the login screen via a "Forgot password?" link injected through `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER`.

### 2.5 Two-Factor Authentication

TOTP-based 2FA using authenticator apps (Google Authenticator, Authy, 1Password, etc.).

**Available to all authenticated users — both `admin` and `user` roles.** 2FA is a personal account-security feature, not an admin privilege. The setup, confirmation, and disable endpoints are gated by `auth:sanctum` only, with no permission requirement.

| Surface | admin | user |
|---|---|---|
| API endpoints (`/api/two-factor/*`) | ✓ | ✓ |
| Filament `/admin/2fa-settings` page | ✓ | ✗ (no panel access) |
| Filament `/admin/two-factor-challenge` page (login challenge) | ✓ | ✗ (no panel access) |
| API `/api/two-factor/challenge` (login challenge) | ✓ | ✓ |

In practice this means the mobile/web client app must implement its own 2FA setup UI for `user`-role users, since they can't reach the Filament settings page. The required API calls are listed below.

**Setup flow (via API or Filament `/admin/2fa-settings`):**
1. User clicks "Enable 2FA" → client calls `POST /api/two-factor/setup`
2. Server generates a TOTP secret, encrypts it, and stores it with `two_factor_confirmed_at = null`
3. Server returns a QR code (data URI) and the secret
4. User scans the QR with their authenticator app
5. User enters a code from the app to confirm → client calls `POST /api/two-factor/confirm` with the code
6. On successful confirmation, server sets `two_factor_confirmed_at = now()` and generates 8 recovery codes (10 alphanumeric chars each)
7. Recovery codes are bcrypt-hashed inside an encrypted JSON blob and shown to the user once

**Login challenge flow:**
1. User submits email + password
2. Server returns a `challenge_token` instead of a Sanctum token
3. User submits `POST /api/auth/2fa/verify` with `{challenge_token, code}` (the code can be either a TOTP code or a recovery code)
4. On success, server returns a Sanctum bearer token
5. Recovery codes are single-use — consumed codes are removed from the encrypted blob

**Storage security:**
- TOTP secret: encrypted via Laravel's `encrypt()`
- Recovery codes: each code is bcrypt-hashed; the array of hashes is JSON-encoded; the JSON is then encrypted. An attacker would need the app key AND a successful brute-force against bcrypt to use a stolen code.

**Admin reset:** Admins can reset any user's 2FA via Filament. This clears `two_factor_secret`, `two_factor_recovery_codes`, and `two_factor_confirmed_at`.

### 2.6 Admin-Created Users & Temp Passwords

When an admin creates a user (via Filament UserResource or `POST /api/users`), the flow is:

1. Admin enters name, email, and role (defaults to `user`)
2. Server generates a 16-character random temp password
3. User is persisted with `must_change_password = true` and `email_verified_at = null`
4. Two emails are queued and sent separately:
    - **Email A:** verification code (same as public registration)
    - **Email B:** temp password notification
5. The plaintext temp password is also displayed to the admin once (Filament persistent toast or API response field) so they can share it out-of-band if needed
6. On first login, the user must verify their email AND change the password before they can use anything else

**Regenerate temp password:** Filament has a row action visible only when `must_change_password = true`. It refuses to run if the user has already changed the password (prevents accidental lockout). This is useful when the user lost the temp password email.

**Authentication gate pipeline:**
The Filament panel runs three middlewares in strict order. Each one skips itself when prerequisites are unmet, so they form a proper pipeline rather than fighting each other:

```
Authenticate
   ↓
EnsureEmailVerified  ──→  redirects to /admin/email-verify if unverified
   ↓
EnsureMustChangePassword  ──→  redirects to /admin/change-password if temp pw still active
   ↓
EnsureTwoFactorChallenge  ──→  redirects to /admin/two-factor-challenge if 2FA enabled but not yet challenged this session
   ↓
   Panel
```

`EnsureMustChangePassword` skips itself when email is unverified (so the email gate runs first). `EnsureTwoFactorChallenge` skips itself when either email is unverified OR `must_change_password` is true. This eliminates the redirect-loop class of bugs.

### 2.7 OAuth2 — Google & Apple Sign-In

Social sign-in via Google and Apple, brokered through **Supabase Auth**. The Laravel backend owns the entire OAuth flow — clients never handle tokens from Google/Apple directly.

**Provider:** Supabase Auth (handles Google and Apple OAuth app credentials, consent screens, and token exchange on behalf of the backend).

**Protocol:** OAuth2 with **PKCE** (Proof Key for Code Exchange). PKCE is used server-side, which prevents authorization code interception attacks even though there is no client secret in the browser/app.

#### Flow

```
Mobile/Web client
      │
      │  Opens browser / WebView to:
      │  GET /api/auth/oauth/{provider}
      ▼
  Laravel (OauthController)
      │  Generates code_verifier + code_challenge (PKCE S256)
      │  Stores verifier in Cache (10-min TTL, keyed by state UUID)
      │  Redirects to Supabase /auth/v1/authorize
      ▼
  Supabase Auth → Google / Apple consent screen
      │  User authenticates
      │  Supabase redirects to:
      │  GET /api/auth/oauth/{provider}/callback?code=xxx&_s={state}
      ▼
  Laravel (OauthController::callback)
      │  Retrieves verifier from Cache using state UUID
      │  POSTs code + verifier to Supabase /auth/v1/token?grant_type=pkce
      │  Receives Supabase user object (id, email, name)
      │  Finds or creates local User (see account resolution below)
      │  Issues Sanctum token
      ▼
  Mobile deep link:
  vidioking://auth/callback?token={sanctum_token}
```

On failure (user cancelled, expired state, Supabase error):
```
vidioking://auth/callback?error={message}
```

#### Account Resolution

| Scenario | Result |
|---|---|
| First sign-in, no existing account | New User created with `role=user`, `email_verified_at=now()`, `must_change_password=false`, `password=null` |
| First sign-in, existing account with same email | Existing account merged — `OauthAccount` row linked; email marked verified if not already |
| Returning OAuth user (same provider + provider_id) | Existing linked account used directly |

#### Behavioural Differences from Email/Password Login

| Aspect | OAuth login | Email/password login |
|---|---|---|
| Email verification gate | Skipped — OAuth provider guarantees it | Required |
| `must_change_password` gate | Skipped — always `false` for OAuth users | Applies if set |
| 2FA challenge | **Skipped** — OAuth is treated as trusted auth | Applies if 2FA is enabled |
| Sanctum token issuance | Direct — no intermediate challenge token | May require 2FA challenge first |

#### Password-Sensitive Operations (2FA)

OAuth-only users (i.e. `password IS NULL`) cannot call:
- `DELETE /api/two-factor` (disable 2FA)
- `POST /api/two-factor/recovery-codes` (regenerate recovery codes)

Both return `403` with: `"This operation is not available for accounts signed in via Google or Apple."`

An OAuth user who sets a password via the forgot-password flow is no longer OAuth-only and can use these operations normally.

#### Database

A new `oauth_accounts` table stores the link between a local User and their OAuth identity:

| Column | Description |
|---|---|
| `user_id` | FK → users |
| `provider` | `'google'` or `'apple'` |
| `provider_id` | Supabase user UUID (stable across logins) |
| `provider_email` | Email from the OAuth provider (informational) |

One user can have multiple rows (one per provider). The `(provider, provider_id)` pair is unique across the system.

#### Filament (Admin Panel)

Admins can sign in to the Filament panel via Google or Apple. Two buttons are shown above the email/password form on the login page. After OAuth authentication:
- The user's web session is established via `Auth::login()`
- A session flag `oauth_authenticated = true` is set
- All three auth-gate middlewares (`EnsureEmailVerified`, `EnsureMustChangePassword`, `EnsureTwoFactorChallenge`) detect this flag and short-circuit
- Only users with `access-admin-panel` permission can complete the Filament OAuth flow; others are redirected to the login page with an error

#### Configuration

New `.env` variables required:

```ini
SUPABASE_URL=https://xyzxyz.supabase.co
SUPABASE_ANON_KEY=eyJ...
OAUTH_MOBILE_REDIRECT_SCHEME=vidioking     # deep link scheme
OAUTH_WEB_REDIRECT_URL=                    # reserved for future web client
```

Supabase dashboard setup required:
1. Enable Google and Apple providers in **Authentication → Providers**
2. Add `{APP_URL}/api/auth/oauth/google/callback` and `{APP_URL}/api/auth/oauth/apple/callback` to **Authentication → URL Configuration → Redirect URLs**
3. Add `{APP_URL}/admin/oauth/google/callback` and `{APP_URL}/admin/oauth/apple/callback` for the Filament flow

---

## 3. Authorization — Roles & Permissions

Backed by `spatie/laravel-permission`. Two roles, ~20 permissions.

### Roles

| Role | Description | How assigned |
|---|---|---|
| `admin` | Full access to Filament panel, every API endpoint, every catalog write | Manually via Filament UserResource form, `PUT /api/users/{id}/roles`, or tinker |
| `user` | API-only access; can read catalog data and manage their own jobs/devices | Automatically assigned on public registration |

Admin is a strict superset of user — admin has every permission user has, plus all the `manage-*` permissions.

### Permission Matrix

| Permission | admin | user | Used for |
|---|---|---|---|
| `access-admin-panel` | ✓ | | Filament `canAccessPanel()` gate |
| `manage-users` | ✓ | | All `/api/users/*` endpoints |
| `view-users` | ✓ | | (reserved for future user-listing endpoints accessible to non-admins) |
| `view-templates` | ✓ | ✓ | `GET /api/templates`, `GET /api/templates/{id}` |
| `manage-templates` | ✓ | | Template create/update/delete |
| `view-categories` | ✓ | ✓ | `GET /api/categories`, `GET /api/categories/{id}` |
| `manage-categories` | ✓ | | Category create/update/delete + datasource detach |
| `view-ai-features` | ✓ | ✓ | `GET /api/ai-features` |
| `manage-ai-features` | ✓ | | AI Feature create/update/delete |
| `view-ai-feature-configs` | ✓ | ✓ | `GET /api/ai-feature-configs` |
| `manage-ai-feature-configs` | ✓ | | Static-token, JWT, switch-auth, delete |
| `view-data-sources` | ✓ | ✓ | (reserved — no API endpoint today, ready for future use) |
| `manage-data-sources` | ✓ | | (reserved) |
| `manage-task-status` | ✓ | | All `/api/task-status/*` endpoints |
| `generate-content` | ✓ | ✓ | `POST /api/generate/{feature}`, `GET /api/status/{jobId}`, `POST /api/upload` |
| `view-own-history` | ✓ | ✓ | `GET /api/history/*`, `DELETE /api/history/{id}` |
| `view-all-history` | ✓ | | (reserved for a future admin-can-see-all-jobs-via-API feature; today admins see other users' jobs only via Filament) |
| `manage-own-devices` | ✓ | ✓ | `POST /api/register-device` |

### Multi-guard registration

Both roles are seeded against both `web` and `sanctum` guards. This means permission checks behave identically whether the request authenticated via Sanctum (API) or Filament's web session.

### How permissions are enforced

**Route-level middleware:**
```php
Route::get('/templates', [TemplateController::class, 'index'])
    ->middleware('permission:view-templates');
```

A failed check returns a 403 JSON envelope:
```json
{
  "success": false,
  "data": null,
  "message": "You do not have permission to perform this action.",
  "errors": null
}
```

The standard envelope is rendered by a custom `UnauthorizedException` handler in `bootstrap/app.php` so spatie's exception is uniform with the rest of the API.

**Ownership scoping (in controllers):**
For endpoints that operate on per-user data (history, status, downloads), the controller scopes queries by `auth()->id()`. Even an admin calling `GET /api/history` sees only their own jobs through the API. Cross-user history access is exclusively a Filament feature.

**Filament panel gate:**
The `User` model implements `FilamentUser::canAccessPanel()`, which returns true only if the user has the `access-admin-panel` permission. Non-admin users get a 403 at the panel even with valid Sanctum credentials.

### Role assignment

- **Public registration** (`POST /api/auth/register`): always assigns `user`
- **Admin user creation** (Filament or `POST /api/users`): admin can specify role; defaults to `user`
- **Promotion/demotion**: `PUT /api/users/{id}/roles` with body `{"roles": ["admin"]}` or via Filament edit form
- **Self-demotion guard:** an admin cannot remove their own admin role through the API (sanity rail to avoid accidental lockout — although it's not a strict invariant since multiple admins can demote each other)

### Existing user backfill

Backfilling roles for users who existed before the permission system was deployed is a manual step:

```bash
php artisan tinker
>>> User::find(1)->assignRole('admin');     # promote a specific user
>>> User::whereDoesntHave('roles')->each(fn($u) => $u->assignRole('user'));   # default everyone else
>>> exit
```

Until a user has at least one role, every protected endpoint returns 403.

---

## 4. AI Generation Pipeline

The core feature: turn a user's prompt + assets into a video via an external AI vendor, asynchronously.

### 4.1 Vendor Abstraction

The system was deliberately designed so adding a new vendor requires zero code changes — only admin panel configuration. This is achieved through a configuration-driven `response_mapping` system.

The previous vendor-specific poll strategies (`KlingPollStrategy`, `ViduPollStrategy`) have been replaced by a single `GenericPollStrategy` that interprets the vendor's response shape via the `response_mapping` config on each `AiFeatureConfig`.

**`response_mapping` structure:**
```json
{
  "generation": {
    "job_id_path": "data.task_id",
    "status_path": "data.task_status"
  },
  "polling": {
    "status_path": "data.task_status",
    "progress_path": "data.progress",
    "error_path": "data.task_status_msg"
  },
  "result": {
    "video_url_path": "data.task_result.videos[0].url",
    "image_url_path": "data.task_result.images[0].url"
  }
}
```

These dot-paths tell the system how to extract job IDs, status, and result URLs from arbitrary vendor JSON shapes.

### 4.2 Generation Request Lifecycle

```
1. Client → POST /api/generate/{feature} with prompt + asset URLs/IDs
2. Server validates input against the AiFeature's required parameters
3. Server snapshots template_id and credit_cost onto the AiJob (preserves history accuracy if templates change later)
4. Server dispatches AiGenerationJob to the queue
5. Job calls vendor API → receives vendor's job ID
6. Job persists AiJob row with status=queued/processing
7. Either:
   - POLLING MODE: AiPollingJob is dispatched on a delay; checks status; reschedules itself until terminal
   - WEBHOOK MODE: vendor calls POST /api/webhook/{configKey}/{jobUuid}; server processes synchronously
8. On completion: AssetDownloadJob downloads video/image to local storage
9. SendPushNotification job fires FCM with permanent local URLs
10. Client polled GET /api/status/{jobId} sees status=completed and the local URLs
```

**Race condition handling:**
A client that polls `/api/status` immediately after `/generate` may hit the endpoint before the queue worker has created the `AiJob` row. The status endpoint responds with `status: queued` in this gap rather than throwing 404, so the client's polling loop just continues without error.

### 4.3 Polling vs. Webhooks

Each `AiFeatureConfig` has a `completion_mode` flag with two values:

| Mode | Behavior |
|---|---|
| `polling` | Server polls vendor on a schedule (`AiPollingJob` reschedules itself). No vendor-side configuration needed beyond the API endpoint. |
| `webhook` | Server registers a callback URL with the vendor; vendor POSTs job completion. Lower latency, no polling load. Includes `WebhookTimeoutJob` as a safety net — if the webhook never arrives, the timeout job runs after a configured interval and falls back to a synchronous status check. |

This is per-config, so different vendors (or different features within a vendor) can use different completion modes.

### 4.4 Asset Permanence

Vendor CDN URLs typically expire (Kling URLs are valid for ~30 days, Vidu varies). Push notifications referencing those URLs would break for users who view the notification weeks later.

The `AssetDownloadJob` solves this:
1. Runs after every successful completion (both polling and webhook paths)
2. Downloads the video and any thumbnail/image from the vendor URL to local disk (path: `storage/app/public/generations/{user_id}/{job_uuid}/`)
3. Updates the `AiJob` row with the local URLs
4. The push notification dispatches AFTER asset download, so it always references local URLs

This means:
- Notifications work indefinitely
- Bandwidth cost is borne by the VPS, not paid for repeatedly each time a user opens an old notification
- Disk usage grows over time → see `generations:cleanup` Artisan command in [Operational Tooling](#10-operational-tooling)

---

## 5. Catalog Management

The "catalog" is the configuration data that defines what users can generate and how. All catalog entities are admin-managed via Filament; users can read most of them via API.

### 5.1 Data Sources

A `DataSource` represents a credential set + base URL for a vendor account. One vendor can have multiple data sources (e.g. dev account vs. prod account). Used to compose `AiFeatureConfig` records.

| Field | Purpose |
|---|---|
| `name` | Human-readable label (e.g. "Kling Production") |
| `base_url` | API base URL |
| `auth_type` | `static_token` or `jwt` |
| `auth_credentials` | Encrypted JSON — token, or JWT issuer/secret |

### 5.2 AI Features

An `AiFeature` is a generation capability exposed to clients (e.g. "image-to-video", "text-to-video"). Identified by a `slug` that appears in the API path: `POST /api/generate/{slug}`.

Each AiFeature has an active `AiFeatureConfig` (see below) determined by its `data_source_id`.

| Field | Purpose |
|---|---|
| `name` | Human-readable label |
| `slug` | URL-safe identifier (`image_to_video`, etc.) |
| `data_source_id` | Which DataSource (and therefore which vendor) handles this feature today |
| `required_inputs` | JSON describing what client must send (prompt, image_url, duration, etc.) |

### 5.3 AI Feature Configs

The `AiFeatureConfig` is where the vendor integration logic lives. One per (AiFeature, DataSource) combination.

| Field | Purpose |
|---|---|
| `endpoint` | Full URL to vendor's generation endpoint |
| `status_check_endpoint` | Vendor's polling endpoint (used in polling mode) |
| `request_body_template` | JSON template used to construct vendor requests; supports `{{placeholder}}` substitution |
| `response_mapping` | The dot-path mapping described in [4.1](#41-vendor-abstraction) |
| `completion_mode` | `polling` or `webhook` |
| `auth_type` | `static_token` or `jwt` (denormalized for query convenience) |

**Auth type variants:**
- `static_token`: simple bearer token (Vidu)
- `jwt`: server signs a JWT with HMAC + the configured secret + a TTL (Kling)

Switching auth types in place is supported via `PATCH /api/ai-feature-configs/{id}/switch-auth`.

### 5.4 Categories

`TemplateCategory` groups templates for browsing/discovery in the client UI. Categories have a many-to-many relationship with both `DataSource` (defines which vendors a category supports) and `Template`.

| Field | Purpose |
|---|---|
| `name` | Human-readable label |
| `slug` | URL-safe |
| `image_url` | Display thumbnail |

### 5.5 Templates & Template Contents

A `Template` is a pre-configured generation recipe — pre-filled prompts, suggested input images, default parameters. Users see templates in the client UI and choose one as a starting point.

A `TemplateContent` is a single example/variant of a template (different sample images, different prompt phrasings). One template has many contents.

**Template metadata:**
- `sample_content_index` — points to a specific `template_contents` row to feature as the headline example
- `data_source_id` — auto-populated from the AI Feature's active vendor config; **does not appear in the Filament template form** (admins shouldn't be choosing this; it follows from the feature)
- `metadata` — flexible JSON for client-specific display hints

**Snapshot at generation time:**
When a user generates from a template, the `template_id` and `credit_cost` are snapshotted onto the resulting `AiJob`. If the template is later edited or its price changes, the historical record stays accurate.

### 5.6 Vendor Status Mappings

A `VendorStatusMapping` translates vendor-specific status strings (`succeed`, `task_failed`, etc.) into the canonical statuses the client API uses (`completed`, `failed`, `processing`). Without these mappings, the client would have to know every vendor's status taxonomy.

API endpoints (`/api/task-status/*`) are admin-only.

---

## 6. Generation History

Endpoints (require `view-own-history`):

| Endpoint | Purpose |
|---|---|
| `GET /api/history` | List the authenticated user's jobs, paginated |
| `GET /api/history/{jobId}` | Detail of one job (own only) |
| `GET /api/history/{jobId}/download` | Stream the asset to the client |
| `DELETE /api/history/{jobId}` | Soft-hide (sets `hidden_at`); does not actually delete the row |

**Soft-hide rather than hard-delete:** the `hidden_at` timestamp lets users "delete" a job from their view without losing the record (useful for support and analytics). The asset files on disk remain until the `generations:cleanup` command processes them.

**Ownership enforcement:** every method scopes queries by `auth()->id()` server-side. A user cannot see another user's job by guessing IDs — they get a 404, identical to a job that doesn't exist (no enumeration leak).

---

## 7. File Uploads

**Endpoint:** `POST /api/upload` (requires `generate-content`)

Used to upload reference images for image-to-video and similar features. Stored under `storage/app/public/uploads/{user_id}/`.

**Filament upload limits:**
The Docker setup includes tuned PHP and Nginx upload limits:
- `upload_max_filesize = 100M`
- `post_max_size = 100M`
- Nginx `client_max_body_size = 100M`

These were tuned specifically for Filament file upload use cases (template thumbnail uploads, datasource credential file imports). Adjust in `docker-compose.yml` and the Nginx config if you need bigger files.

---

## 8. Push Notifications

**Provider:** Firebase Cloud Messaging (FCM)

**Trigger:** Fires automatically after every AI generation reaches a terminal state (completed or failed), in both polling and webhook completion modes.

**Architecture:**
- `Device` model stores per-user FCM device tokens
- `POST /api/register-device` (requires `manage-own-devices`) — clients register/refresh their FCM token at login or app startup
- `SendPushNotification` job consumes the `AiJob` after `AssetDownloadJob` finishes
- Notification payload includes the local asset URL (permanent, see [4.4](#44-asset-permanence))

**FCM service:** abstracted behind `PushNotificationServiceInterface` so an alternative provider (APNs direct, OneSignal, etc.) could be swapped in without touching call sites.

---

## 9. Admin Panel (Filament)

Accessible at `/admin`. Gated by `FilamentUser::canAccessPanel()` returning true only for users with `access-admin-panel` permission (admins).

### Resources (CRUD UIs)

| Resource | Purpose |
|---|---|
| **UserResource** | Manage users — name, email, role (admin/user), see verified/2FA/temp-password state |
| **DataSourceResource** | Manage vendor credentials |
| **AiFeatureResource** | Manage generation features. Has a relation manager for the feature's configs |
| **AiFeatureConfigResource** | Manage per-feature, per-vendor configuration (response_mapping, completion_mode, etc.) |
| **TemplateCategoryResource** | Manage browse categories |
| **TemplateResource** | Manage generation templates with their contents |
| **VendorStatusMappingResource** | Manage vendor → canonical status translations |

### Custom Pages

| Page | Path | Purpose |
|---|---|---|
| Email Verify | `/admin/email-verify` | Where unverified admins are forced to enter their 6-digit code |
| Change Password | `/admin/change-password` | Where temp-password admins must change their password |
| Two Factor Challenge | `/admin/two-factor-challenge` | TOTP/recovery code prompt during login |
| Two Factor Settings | `/admin/2fa-settings` | Self-service 2FA enable/disable |
| Forgot Password | `/admin/forgot-password` | Public reset flow (linked from login) |

### UserResource specifics

- **Create form:** name, email, role (default `user`). Password is auto-generated; `must_change_password` and verification flow are auto-set
- **Edit form:** name, email, role
- **Table columns:** name, email, role badge (red for admin, gray for user), email verified icon, 2FA enabled icon, must-change-password indicator, created_at
- **Filters:** trashed, role, verified/unverified, 2FA enabled
- **Row actions:** Resend verification, Regenerate temp password (visible only when applicable)

### Admin row actions of note

- **Regenerate temp password** — re-runs the temp-password flow (new password generated, both emails re-sent). Visible only when `must_change_password = true` AND user has not already changed password since.
- **Reset 2FA** — clears `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` (use when a user loses their authenticator device).
- **Resend verification email** — re-issues a 6-digit verification code (subject to the 60s cooldown).

---

## 10. Operational Tooling

### Artisan commands

| Command | Purpose |
|---|---|
| `php artisan migrate` | Run pending migrations |
| `php artisan db:seed --class=RolesAndPermissionsSeeder` | Seed roles + permissions (idempotent — safe to re-run) |
| `php artisan permission:cache-reset` | Flush spatie's permission cache (run after seeder or any direct DB role/permission edit) |
| `php artisan generations:cleanup` | Delete on-disk assets for jobs older than a configurable retention period |
| `php artisan queue:restart` | Signal queue workers to restart after deploy (PM2 actually does this via `pm2 restart all`) |

### Queue workers

Run via PM2 (`queue.sh` script invokes `php artisan queue:work` with the right options). After every code deploy, queue workers must be restarted (`pm2 restart all`) — failing to do so means workers run stale code and bugs that have already been fixed appear to recur.

### DNS and deployment hygiene gotchas

A few non-obvious things that have caused incidents in the past and are worth knowing:

- **DNS A records can drift:** if A/records point to a wrong IP (e.g. an old VPS), external traffic never hits the correctly-configured server. Symptoms: changes work locally and on the server's curl-to-localhost, but external clients see stale behavior. Always verify DNS resolution after any IP change.
- **CORS is layered:** both Nginx and Laravel can add CORS headers; if both are configured, headers are duplicated and browsers reject. The convention here is: Nginx handles CORS for API routes via a `map` block (multiple allowed origins); Laravel's CORS middleware is disabled for `/api/*`.
- **Queue worker code freshness:** see above; PM2 restart is mandatory after deploy.

---

## 11. Email Infrastructure

**Provider:** Gmail SMTP (with App Password)

**Required Gmail account settings:**
- 2-Step Verification must be enabled on the Google account
- An App Password must be generated and used as `MAIL_PASSWORD`
- `MAIL_FROM_ADDRESS` must match `MAIL_USERNAME` (Gmail rejects mismatched From headers)

**Notifications:**
All transactional emails (`VerifyEmailNotification`, `TemporaryPasswordNotification`, `PasswordResetNotification`) implement `ShouldQueue`. SMTP delivery never blocks the HTTP request — emails are dispatched to the queue and sent by workers.

**Switching providers:**
SendGrid (or any other SMTP provider) can be swapped in via `.env` only — no code change needed. Update `MAIL_*` variables and clear config cache.

**Email content:**
All emails use the standard Laravel markdown notification layout. Codes are displayed prominently in the body with no clickable link (mobile-first design — codes work without app-switching).

---

## 12. Security Posture

### Credentials & secrets at rest

| Data | Storage |
|---|---|
| User passwords | bcrypt (Laravel default) |
| 2FA TOTP secrets | Encrypted via Laravel `encrypt()` (uses app key) |
| 2FA recovery codes | Each code bcrypt-hashed; array of hashes JSON-encoded; JSON encrypted |
| Sanctum tokens | SHA-256 hashed before DB storage; only the plaintext returned at issuance can authenticate |
| Vendor credentials (DataSource auth_credentials) | Encrypted JSON column |
| Email verification codes | bcrypt-hashed in DB; user submits plaintext, server compares with `Hash::check` |

### Endpoint-level controls

- **Rate limiting:** `/api/generate/{feature}` is throttled to 10 req/min per user. Other endpoints use the default `throttle:api` rate.
- **Verification code attempts:** capped at 5 per code; exceeded → code invalidated.
- **Resend cooldown:** 60s between code re-issues per email address.
- **2FA challenge token:** cache-backed UUID with a **5-minute TTL** so an intercepted challenge token can't be replayed indefinitely.

### Anti-enumeration

- Login error messages are uniform regardless of whether the email exists
- Forgot-password endpoint always returns 200 regardless of whether the email exists
- Verification-code-not-found and verification-code-expired return identical errors

### Mass assignment

All models use `protected $guarded = []` (open mass assignment). The defense is at the request layer — `FormRequest` classes (`StoreUserRequest`, `RegisterRequest`, etc.) explicitly enumerate allowed fields before passing data to the model. **Do not bypass `FormRequest` validation when calling `Model::create()` from a controller.**

### Authorization model

See [Section 3](#3-authorization--roles--permissions). Permission middleware on routes is the primary gate; controller-level ownership checks (`where('user_id', auth()->id())`) are the secondary gate for per-user data.

---

## 13. Configuration Reference

### Environment variables (`.env`)

| Group | Variable | Purpose |
|---|---|---|
| **App** | `APP_KEY` | Encryption key — never lose this; everything encrypted at rest depends on it |
| | `APP_URL` | Used for email links and CORS origin checks |
| **Database** | `DB_*` | MySQL connection |
| **Mail** | `MAIL_MAILER=smtp` | |
| | `MAIL_HOST=smtp.gmail.com` | |
| | `MAIL_PORT=587` | |
| | `MAIL_USERNAME` | Gmail address |
| | `MAIL_PASSWORD` | App Password (16 chars, no spaces) |
| | `MAIL_FROM_ADDRESS` | Must match `MAIL_USERNAME` for Gmail |
| **Sanctum** | `SANCTUM_STATEFUL_DOMAINS` | For SPA auth via cookies (not used by mobile, used by web client) |
| **Firebase** | `FCM_SERVER_KEY` | Server key for FCM |
| | `FCM_PROJECT_ID` | Firebase project ID |
| **Queue** | `QUEUE_CONNECTION=database` | Default queue driver |

### Permission cache

Permissions are cached for 24 hours. After any direct DB modification of roles/permissions OR after running the seeder, run `php artisan permission:cache-reset` to flush.

### Filament panel ID

The admin panel ID is `admin` (so the panel mounts at `/admin`). Don't change this without also updating the path patterns in the gate middlewares (`EnsureEmailVerified`, `EnsureMustChangePassword`, `EnsureTwoFactorChallenge`).

---

## 14. Glossary

| Term | Definition |
|---|---|
| **AiFeature** | A generation capability exposed to clients (e.g. image-to-video). Has a slug used in the API path. |
| **AiFeatureConfig** | The vendor integration configuration for an AiFeature. Defines endpoint URLs, auth, response mapping. |
| **AiJob** | A single generation request from a user. Persists across the polling lifecycle and stores the final result URLs. |
| **DataSource** | A vendor credential set + base URL. One vendor can have multiple (dev/prod). |
| **Generic Poll Strategy** | The single config-driven strategy that handles all vendor polling. Replaced the older vendor-specific strategies. |
| **Response Mapping** | The dot-path JSON config that tells the system how to extract job IDs, status, and URLs from arbitrary vendor JSON. |
| **Completion Mode** | Per-config setting: `polling` or `webhook`. Determines how the system learns about job completion. |
| **Asset Download Job** | The queue job that copies vendor CDN assets to permanent local storage after generation completes. |
| **Recovery Code** | One of 8 single-use 10-character codes generated when 2FA is enabled, used to log in if the authenticator app is lost. |
| **Challenge Token** | Short-lived cache-backed UUID (5-minute TTL) issued during login when 2FA is required, exchanged for a Sanctum token after the user supplies a TOTP code. |
| **Change Token** | Cache-backed UUID (same mechanism as challenge token) issued during login when `must_change_password` is true, consumed by `/auth/password/change-initial` which returns a Sanctum token directly. |
| **OauthAccount** | A row in `oauth_accounts` linking a local User to an external OAuth identity (Google or Apple). One user can have one row per provider. |
| **OAuth-only user** | A User whose `password` column is `null` — they can only authenticate via OAuth. Password-sensitive operations (2FA disable, recovery code regen) return 403 for these users. |
| **PKCE** | Proof Key for Code Exchange — the OAuth2 extension used for the server-side OAuth flow. Prevents auth code interception by binding the authorization request to a one-time verifier. |
| **Sanctum Token** | The opaque bearer token returned to authenticated API clients. Stored hashed in `personal_access_tokens`. |
| **Filament User Contract** | Interface implemented by `User` that gates panel access via `canAccessPanel()`. |
| **must_change_password** | User flag set true for admin-created users; cleared on first password change. Forces a redirect to `/admin/change-password` until cleared. |
| **`hidden_at`** | Soft-hide timestamp on `ai_jobs`; user-facing "delete" sets this rather than actually deleting the row. |

---

*End of feature documentation. For deployment specifics, see `Deployment.md`. For architectural rationale, see `Architecture.md`. For API examples, see `INSTRUCTIONS.md`.*