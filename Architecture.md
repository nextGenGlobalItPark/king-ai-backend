# Vidio King — Architecture

## Table of Contents

- [System Overview](#system-overview)
- [High-Level Architecture](#high-level-architecture)
- [Database Schema](#database-schema)
- [Core Design Patterns](#core-design-patterns)
- [Request Lifecycle: AI Generation](#request-lifecycle-ai-generation)
- [Vendor Integration Layer](#vendor-integration-layer)
- [Authentication and Authorization](#authentication-and-authorization)
- [Queue System](#queue-system)
- [Asset Storage Pipeline](#asset-storage-pipeline)
- [Push Notification System](#push-notification-system)
- [Admin Panel (Filament)](#admin-panel-filament)
- [API Layer](#api-layer)
- [Directory Structure](#directory-structure)
- [Configuration System](#configuration-system)
- [Error Handling](#error-handling)

---

## System Overview

Vidio King is an AI video generation middleware that sits between client applications (mobile/web) and AI vendor APIs (Kling, Vidu). It provides a unified, vendor-agnostic interface for generating AI videos while handling authentication, queued processing, asset storage, and push notifications.

The system is designed around one core principle: **vendor integrations are entirely configuration-driven**. Adding a new AI vendor, changing API endpoints, or modifying response parsing requires zero code changes — everything is managed through the Filament admin panel and database configuration.

```
┌─────────────┐     ┌──────────────────────────────────────────┐     ┌─────────────┐
│  Mobile App  │────▶│              Vidio King                  │────▶│  Kling API  │
│  (Next.js)   │◀────│                                          │◀────│             │
└─────────────┘     │  Laravel 12 / PHP 8.2 / MySQL / Nginx    │     └─────────────┘
                    │                                          │
                    │  • Auth (Sanctum)                        │     ┌─────────────┐
                    │  • Queue Workers (Generation/Polling)     │────▶│  Vidu API   │
                    │  • Asset Storage (Local filesystem)       │◀────│             │
                    │  • Push Notifications (Firebase FCM)      │     └─────────────┘
                    │  • Admin Panel (Filament)                 │
                    └──────────────────────────────────────────┘
```

---

## High-Level Architecture

The system is organized into six major layers:

**API Layer** — Controllers, Form Requests, and API Resources handle HTTP requests, validate input, format responses, and enforce authentication via Sanctum bearer tokens.

**Service Layer** — `AiGenerationService` orchestrates the generation and polling logic. `AuthStrategyResolver` and `PollStrategyResolver` select the right strategy for each vendor. `FCMPushService` handles push delivery. `UserService` handles user operations.

**Strategy Layer** — Implements the Strategy Pattern for vendor-specific behavior. `GenericPollStrategy` handles all vendors using configurable response mappings. `JwtAuthStrategy` and `StaticTokenAuthStrategy` handle different authentication methods.

**Job Layer** — Queued jobs handle asynchronous operations: `AiGenerationJob` sends requests to vendors, `AiPollingJob` checks task status with progressive backoff, `AssetDownloadJob` downloads completed assets to local storage, `WebhookTimeoutJob` acts as a safety net for callback mode, and `SendPushNotification` delivers FCM notifications.

**Event Layer** — Events and listeners decouple business logic. `AiJobCompletedEvent` triggers push notifications on generation completion. `TemplateEvent` and `CategoryEvent` notify users of content changes.

**Admin Layer** — Filament 3 resources provide a full CRUD admin panel for managing all configuration — vendors, features, templates, status mappings, and users.

---

## Database Schema

### Entity Relationship Overview

```
users
  │
  ├──< devices                    (user has many devices for push notifications)
  ├──< ai_jobs                    (user has many generation jobs)
  └──  created_by on most tables  (audit trail)

data_sources                       (vendors: Kling, Vidu)
  │
  ├──< ai_feature_configs          (vendor-specific API configuration)
  ├──< vendor_status_mappings      (maps vendor statuses to internal statuses)
  ├──< ai_jobs                     (which vendor processed the job)
  ├──>< template_categories        (many-to-many via template_category_data_sources)
  └──< ai_features                 (which vendor is active for this feature)

ai_features                        (feature types: image2video, text2video, effects)
  │
  ├──< ai_feature_configs          (one feature can have configs for multiple vendors)
  └──< templates                   (templates are tied to a feature)

ai_feature_configs                 (the core configuration — endpoints, auth, schemas, mappings)
  │
  └── Drives the entire generation/polling pipeline

templates                          (user-facing templates with metadata)
  │
  ├──< template_contents           (sample images, preview videos)
  ├──>< template_categories        (many-to-many via template_category_template)
  └──< ai_jobs                     (which template was used for this job)

template_categories                (grouping for templates)
  │
  ├──>< templates                  (many-to-many)
  └──>< data_sources               (many-to-many)
```

### Table Details

**users** — Application users with Sanctum token authentication, email verification, two-factor authentication, and forced-password-change support.
Columns: id, name, email, password, email_verified_at, must_change_password, two_factor_secret (encrypted), two_factor_recovery_codes (encrypted JSON), two_factor_confirmed_at, remember_token, timestamps, soft_deletes.

**email_verification_codes** — Active 6-digit codes for email verification. One row per user (unique index on user_id); new requests overwrite the previous code.
Columns: id, user_id (FK, unique), code (bcrypt-hashed), attempts, expires_at, last_sent_at, timestamps.

**password_reset_codes** — Active 6-digit codes for the forgot-password flow. Same structure as email_verification_codes; separate table so a user can have an active verification code AND an active reset code at the same time.
Columns: id, user_id (FK, unique), code (bcrypt-hashed), attempts, expires_at, last_sent_at, timestamps.

**roles, permissions, model_has_roles, model_has_permissions, role_has_permissions** — `spatie/laravel-permission` v6 tables. Standard schema. See [Authentication and Authorization](#authentication-and-authorization).

**devices** — FCM device tokens for push notifications.
Columns: id, user_id (FK), token, type (fcm/apns/onesignal), platform (android/ios), is_active, timestamps. Unique constraint on [user_id, token].

**data_sources** — AI vendor definitions (Kling, Vidu, etc.).
Columns: id, name, url, description, created_by (FK), timestamps, soft_deletes.

**ai_features** — Feature types available in the system.
Columns: id, name (unique — e.g., "image2video", "text2video", "effects"), description, is_active, data_source_id (FK — the active vendor for this feature), created_by (FK), timestamps, soft_deletes.

**ai_feature_configs** — The central configuration table that drives the entire generation pipeline. One feature can have multiple configs (for different vendors or fallback ordering).
Columns: id, ai_feature_id (FK), data_source_id (FK), endpoint, status_check_endpoint, completion_mode (polling/callback), webhook_secret, method, auth_type, auth_header_key, auth_value_from_env, auth_secret_key_env, jwt_expiry_seconds, jwt_nbf_offset_seconds, content_type, body_format, standard_schema (JSON), request_body_template (JSON), response_mapping (JSON), is_active, priority, created_by (FK), timestamps, soft_deletes.

**templates** — User-facing templates with structured metadata for the mobile app.
Columns: id, name, credit_cost, metadata (JSON — description, output options, input fields, guide), ai_feature_id (FK), data_source_id (FK — auto-filled from ai_feature), is_active, state (test/live), sequence, created_by (FK), timestamps, soft_deletes.

**template_contents** — Sample images, preview videos, and other media attached to templates.
Columns: id, template_id (FK), template_content_type (image/video/cover), template_content (file path), text (caption), created_by (FK), timestamps, soft_deletes.

**template_categories** — Category grouping for templates.
Columns: id, template_category_name, template_category_slug, is_active, state, sequence, created_by (FK), timestamps, soft_deletes.

**template_category_template** — Pivot table for templates to categories many-to-many.
Columns: id, template_id (FK), template_category_id (FK), timestamps.

**template_category_data_sources** — Pivot table for categories to data sources many-to-many.
Columns: id, template_category_id (FK), datasource_id (FK), created_by, source_db_id, timestamps.

**ai_jobs** — Tracks every generation request through its lifecycle.
Columns: id, queue_job_id (UUID — client-facing identifier), task_id (vendor task ID), feature, template_id (FK), credit_cost (snapshot at generation time), data_source_id (FK), input (JSON), result (JSON), status, progress, hidden_at, user_id (FK), timestamps, soft_deletes. Indexed on [user_id, status].

**vendor_status_mappings** — Maps vendor-specific status strings to internal statuses.
Columns: id, data_source_id (FK, unique), status_mapping (JSON — keys: processing, completed, failed; values: arrays of vendor status strings), created_by (FK), timestamps, soft_deletes.

**jobs / failed_jobs** — Laravel queue tables for job processing and failure tracking.

---

## Core Design Patterns

### Strategy Pattern — Vendor Authentication

Different vendors use different auth methods. The `AuthStrategyResolver` selects the right strategy based on the config's `auth_type`:

```
AuthStrategyResolver::resolve(config)
  ├── auth_type = "jwt"          → JwtAuthStrategy
  │                                 (generates HS256 JWT with access_key/secret_key)
  └── auth_type = "static_token" → StaticTokenAuthStrategy
                                    (reads API key from config, prepends "Token"/"Bearer")
```

Both implement `AuthStrategyInterface::generateAuthHeader()`, returning the complete Authorization header value.

### Strategy Pattern — Vendor Polling

`PollStrategyResolver` always returns `GenericPollStrategy` — all vendor-specific behavior is driven by the `response_mapping` JSON configuration. No vendor-specific poll strategy classes are needed.

`GenericPollStrategy` uses dot-notation paths from the response mapping to extract status, progress, assets, error messages, and thumbnails from any vendor's response format. It also maps vendor status strings to internal statuses using `VendorStatusMapping` from the database.

### Configuration-Driven Architecture

The three JSON fields on `ai_feature_configs` define the entire vendor integration without code:

`standard_schema` defines what input fields the mobile app sends, their types, validation rules, and defaults. The backend validates input against this schema.

`request_body_template` maps the standard schema fields to the vendor's expected request body format using `{{placeholder}}` substitution. Supports nested JSON structures, type-aware casting (string, integer, float, boolean, array), and automatic cleanup of unreplaced placeholders.

`response_mapping` is structured in three sections. `generation` defines how to extract the task_id from the initial generation response and check for errors. `polling` defines the HTTP method, URL template, and paths for status/progress extraction. `result` defines paths for extracting completed assets (video URLs, thumbnails, durations).

Example response_mapping:
```json
{
  "generation": {
    "task_id_path": "data.task_id",
    "error_code_path": "code",
    "error_message_path": "message",
    "success_codes": [0]
  },
  "polling": {
    "method": "GET",
    "url_template": "{status_check_endpoint}/{task_id}",
    "status_path": "data.task_status",
    "progress_path": null,
    "error_message_path": "data.task_status_msg"
  },
  "result": {
    "asset_array_path": "data.task_result.videos",
    "asset_url_path": "url",
    "asset_id_path": "id",
    "duration_path": "duration",
    "thumbnail_path": "cover_url"
  }
}
```

### Template Metadata System

Templates carry a `metadata` JSON field that provides user-facing instructions to the mobile app:

`description` — what this template does. `output_type` — video or image. `preview_video_content_index` — references a template_content entry for preview. `options` — selectable quality/format choices (resolution, duration, aspect_ratio, mode) with values arrays and defaults. `guide.input_fields` — defines what the user provides (image uploads, text prompts) with labels, sample image references, tips, and placeholders. `guide.notes` and `guide.limitations` — user-facing help text.

The API resolves `sample_content_index` references to actual URLs from `template_contents`, so the mobile app receives ready-to-use image URLs.

---

## Request Lifecycle: AI Generation

This is the core flow — from client request to completed video with push notification:

```
1. CLIENT                POST /api/generate/image2video
                         { input: { image: "...", prompt: "..." }, template_id: 3 }
                                    │
2. AiController          Validates input, resolves AiFeature + Template
                         Snapshots template credit_cost
                         Creates UUID, dispatches AiGenerationJob
                         Returns: { job_id: "uuid" }
                                    │
3. AiGenerationJob       Resolves DataSource, creates ai_jobs record
   [ai_generation        Resolves completion_mode from AiFeatureConfig
    queue]               Calls AiGenerationService::generate()
                                    │
4. AiGenerationService   Loads AiFeatureConfig (schema, template, mapping)
                         Applies schema defaults to input
                         Injects callback_url if callback mode
                         Validates input against standard_schema
                         Builds request body from request_body_template
                         Resolves auth via AuthStrategyResolver
                         Sends HTTP request to vendor API
                         Extracts task_id from response using response_mapping
                         Returns response to job
                                    │
5. AiGenerationJob       Saves task_id to ai_jobs
                         ┌─── completion_mode? ───┐
                         │                        │
                    "polling"               "callback"
                         │                        │
6a. AiPollingJob         │               6b. WebhookTimeoutJob
    [ai_polling queue]   │                   dispatched with
    Dispatched with      │                   20-min delay
    10s initial delay    │                        │
         │               │               Vendor completes task
    Calls AiGeneration-  │                        │
    Service::pollStatus()│               POST /api/webhook/{jobId}
         │               │                        │
    GenericPollStrategy  │               WebhookController
    parses response      │               Uses same GenericPollStrategy
    using response_      │               to parse payload
    mapping              │                        │
         │               │                        │
    ┌── status? ──┐      │               ┌── status? ──┐
    │    │    │          │               │    │    │
completed processing failed            completed processing failed
    │    │    │          │               │         │
    │    │    └── Update │               │         └── Update ai_jobs
    │    │        ai_jobs│               │             Fire event
    │    │               │               │
    │    └── Re-dispatch │               │
    │        with backoff│               │
    │        (10→20→40→  │               │
    │         60s)       │               │
    │                    │               │
    └────────────────────┴───────────────┘
                         │
7. AssetDownloadJob      Downloads video + thumbnail from vendor CDN
   [ai_polling queue]    to storage/app/public/generations/{user_id}/{job_id}/
                         Replaces vendor URLs with permanent local URLs
                         in ai_jobs.result
                         If download fails: keeps vendor URLs as fallback
                                    │
8. AiJobCompletedEvent   Fired after assets are downloaded
                                    │
9. AiJobCompleted-       Gets user's active device tokens
   PushNotification      Sends FCM push with job_id, feature, status
   [notifications queue]
                                    │
10. CLIENT               Receives push notification
                         Calls GET /api/status/{jobId} or GET /api/history/{jobId}
                         Gets permanent local video URLs
```

---

## Vendor Integration Layer

### How a vendor request is built

The `AiGenerationService::generate()` method transforms user input into a vendor-specific API request through these steps:

1. Load `standard_schema` — defines available fields, types, defaults
2. Apply defaults — fill in missing fields from schema defaults
3. Inject callback URL — if callback mode, set `callback_url` to webhook endpoint
4. Validate — check required fields, types, constraints against schema rules
5. Build request body — replace `{{placeholders}}` in `request_body_template` with actual values, type-cast based on schema field types
6. Resolve auth — `AuthStrategyResolver` picks JWT or static token strategy, generates Authorization header
7. Send request — HTTP client sends to vendor endpoint with configured method, headers, body format

### How a vendor response is parsed

`GenericPollStrategy::parseResponse()` handles all vendors using paths from `response_mapping`:

1. Extract vendor status from `polling.status_path` (e.g., `data.task_status`)
2. Map to internal status via `VendorStatusMapping` (e.g., "succeed" → "completed")
3. If completed: extract assets from `result.asset_array_path`, map each asset's url/id/duration/thumbnail using configured paths
4. If failed: extract error from `polling.error_message_path`
5. If processing: extract progress from `polling.progress_path` if available

### Adding a new vendor (zero code changes)

1. Create a `DataSource` record (admin panel)
2. Create a `VendorStatusMapping` with the vendor's status strings
3. Create an `AiFeatureConfig` with the vendor's endpoint, auth settings, schema, request template, and response mapping
4. Add API credentials to `config/vendor-credentials.php` and `.env`
5. Run `php artisan config:cache`

---

## Authentication and Authorization

The system has two parallel authenticated surfaces — the API (Sanctum) and the Filament admin panel (web sessions) — both backed by the same `User` model and routed through the same gating logic.

### Client authentication (API)

The API uses Laravel Sanctum with bearer tokens. Clients call `POST /api/auth/login` with email + password and receive a token, then include it in subsequent requests as `Authorization: Bearer {token}`. Tokens are stored hashed in `personal_access_tokens` and can be revoked at logout.

**Public registration does NOT return a token.** `POST /api/auth/register` creates the user with `email_verified_at = null`, sends a 6-digit verification code, and returns 201 with `requires_email_verification: true`. The client must call `POST /api/auth/email/verify` with the code, then call `/auth/login` to obtain a token. This deliberate two-step is enforced to make sure a token is never issued to an unverified email.

### Login flow priority

The login endpoint checks five conditions in strict priority order. The first failure short-circuits and returns its response; lower-priority checks are not reached.

| # | Check | Failure response |
|---|---|---|
| 1 | Credentials valid | 401 `Invalid credentials` |
| 2 | Email verified | 403 `requires_email_verification: true` (auto-resends a fresh code subject to 60s cooldown) |
| 3 | Must change password | 200 with `change_token` and `requires_password_change: true` |
| 4 | 2FA enabled | 200 with `challenge_token` and `requires_two_factor: true` |
| 5 | All passed | 200 with Sanctum bearer `token` |

This ordering prevents enumeration (an attacker can't tell whether a wrong password is for an unverified vs. fully-active account) while still giving the legitimate client an actionable next step.

### Email verification

Six-digit numeric codes (mobile-keypad-friendly). 15-minute expiry, max 5 verification attempts per code (after which the code is invalidated), 60-second cooldown between resends. Codes are bcrypt-hashed in the `email_verification_codes` table; the user submits plaintext and the server compares with `Hash::check`.

`EmailVerificationService` is the single source of truth — it issues codes, enforces cooldowns, verifies submissions, and is called from both the API (`EmailVerificationController`) and the Filament `EmailVerify` page. The API endpoints are `POST /api/auth/email/verify` and `POST /api/auth/email/resend`.

### Forgot password

Same 6-digit code mechanism as verification, but a separate table (`password_reset_codes`) so a user can have both an active verification code and an active reset code simultaneously. `POST /api/auth/password/forgot` issues a code; `POST /api/auth/password/reset` verifies the code and sets the new password.

Resetting the password also clears the `must_change_password` flag if it was set. Reset does NOT issue a Sanctum token — the user must log in afterward, which preserves the 2FA gate. Filament has a `/admin/forgot-password` page wired into the login screen via `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER`.

### Two-factor authentication (TOTP)

Backed by `pragmarx/google2fa` and `bacon/bacon-qr-code`. `TwoFactorService` directly instantiates `new Google2FA()` rather than resolving via the container alias (which previously caused `BindingResolutionException` on fresh deploys).

**Setup:** the user calls `POST /api/two-factor/setup` (or visits `/admin/2fa-settings`); the server generates a TOTP secret, encrypts it with `encrypt()`, and stores it with `two_factor_confirmed_at = null`. The response contains a QR code data URI and the secret. The user scans the QR with an authenticator app and submits a code via `POST /api/two-factor/confirm` to activate. On confirmation, the server sets `two_factor_confirmed_at = now()` and generates 8 single-use recovery codes.

**Recovery code storage:** each 10-character recovery code is bcrypt-hashed; the array of hashes is JSON-encoded; the JSON is encrypted with `encrypt()` and stored in `two_factor_recovery_codes`. An attacker would need both the app key and a successful brute-force against bcrypt to use a stolen code.

**Login challenge:** when login finds a user with `two_factor_confirmed_at` set, it returns a signed, time-limited `challenge_token` instead of a Sanctum token. The client submits `POST /api/two-factor/challenge` with the challenge_token plus either a TOTP code or a recovery code; on success it receives a Sanctum token. Recovery codes are consumed on use (removed from the encrypted blob).

**Availability:** the API endpoints (`/api/two-factor/*`) are open to any authenticated user — both `admin` and `user` roles. The Filament settings/challenge pages require panel access (admin-only). Mobile/web clients must therefore implement their own 2FA setup UI for `user`-role accounts.

### Admin-created users and forced password change

When an admin creates a user (Filament UserResource form or `POST /api/users`), `UserService::createUserWithTemporaryPassword` runs in a DB transaction:

1. Generates a 16-character random temp password
2. Persists the user with `must_change_password = true` and `email_verified_at = null`
3. Assigns a role (defaults to `user`; admin can override)
4. Queues two emails: verification code (`VerifyEmailNotification`) and temp password (`TemporaryPasswordNotification`)
5. Returns the plaintext temp password to the admin once (Filament persistent toast or API response field) so they can share it out-of-band if needed

On first login, the user is gated through email verification, then forced to change the password (`PasswordChangeController`), which clears the `must_change_password` flag.

### Filament panel auth pipeline

The admin panel runs four authenticated middlewares in strict order:

```
Authenticate (Filament's own)
   ↓
EnsureEmailVerified  ──→  redirect to /admin/email-verify if email_verified_at IS NULL
   ↓
EnsureMustChangePassword  ──→  redirect to /admin/change-password if must_change_password = true
   ↓
EnsureTwoFactorChallenge  ──→  redirect to /admin/two-factor-challenge if 2FA enabled but not yet challenged this session
   ↓
   panel
```

**Path-based whitelisting:** each middleware identifies its own page (and Livewire's `livewire/update`, `livewire/upload-file` paths so form submissions on gate pages aren't bounced) by URL path, not by route name. Filament's auto-generated route names for pages registered via `pages([])` vary by version and slug format, and matching by name caused redirect loops where a middleware redirected its own page back to itself.

**Cascading prerequisite skip:** each downstream middleware skips itself when an earlier prerequisite is unmet. `EnsureMustChangePassword` no-ops if the user is unverified (lets the email gate redirect first). `EnsureTwoFactorChallenge` no-ops if the user is unverified or still has `must_change_password = true`. Without this, the gates would ping-pong each other — e.g. a user with both unverified email and must_change_password would bounce between `/admin/email-verify` and `/admin/change-password` forever.

The session-level `two_factor_passed` flag is cleared on logout (since `StartSession` + `AuthenticateSession` tear down the whole session), so re-login always re-challenges.

### Authorization — roles and permissions

Authorization is provided by `spatie/laravel-permission` v6. Two roles are seeded:

| Role | Description | How assigned |
|---|---|---|
| `admin` | Full access. Has every permission in the system. | Manually via Filament UserResource form, `PUT /api/users/{id}/roles`, or tinker |
| `user` | API-only. Read access to catalog data; full access to own jobs, history, devices. | Automatically assigned on public registration |

Both roles are registered against both `web` (Filament) and `sanctum` (API) guards so checks behave identically regardless of authentication surface.

**Permission matrix (abridged):**

| Permission | admin | user |
|---|---|---|
| `access-admin-panel` | ✓ | |
| `manage-users`, `manage-templates`, `manage-categories`, `manage-ai-features`, `manage-ai-feature-configs`, `manage-data-sources`, `manage-task-status` | ✓ | |
| `view-templates`, `view-categories`, `view-ai-features`, `view-ai-feature-configs`, `view-data-sources` | ✓ | ✓ |
| `generate-content`, `view-own-history`, `manage-own-devices` | ✓ | ✓ |
| `view-all-history` | ✓ | (reserved — not gating any endpoint today) |

Admin is a strict superset of user — anything a user can do, an admin can also do. The full catalog lives in `database/seeders/RolesAndPermissionsSeeder.php`, which is idempotent: re-running it adds new permissions, re-syncs role mappings, and leaves existing user role assignments untouched.

**Enforcement layers:**

1. **Route middleware** — every authenticated route has `permission:{name}` middleware (e.g. `permission:view-templates`). A failed check throws `Spatie\Permission\Exceptions\UnauthorizedException`, which is rendered to the standard `{success, data, message, errors}` JSON envelope by an exception renderer in `bootstrap/app.php`.
2. **Filament panel gate** — the `User` model implements `FilamentUser::canAccessPanel()`, returning true only if the user has `access-admin-panel`. Non-admins get a 403 at the panel even with valid credentials.
3. **Controller-level ownership** — for per-user data (history, status, downloads), controllers scope queries by `auth()->id()`. Even an admin calling `GET /api/history` sees only their own jobs through the API. Cross-user history access is exclusively a Filament feature.

**Existing user backfill** is a manual step — every existing user gets 403 on protected endpoints until they have a role assigned:

```bash
php artisan tinker
>>> User::find(1)->assignRole('admin');
>>> User::whereDoesntHave('roles')->each(fn($u) => $u->assignRole('user'));
```

After running the seeder or any direct DB role/permission edit, run `php artisan permission:cache-reset` to flush spatie's 24-hour permission cache.

### Vendor authentication

The system authenticates with vendor APIs using one of two strategies:

`StaticTokenAuthStrategy` reads an API key from config (resolved from environment variables via `config/vendor-credentials.php`) and prefixes it with the configured auth type (e.g., "Token", "Bearer").

`JwtAuthStrategy` generates an HS256 JWT using the vendor's access key (as issuer) and secret key. The token includes `iss`, `exp` (configurable, default 30 minutes), and `nbf` (with configurable clock skew tolerance). Used by Kling.

Credentials are never stored in the database — only the environment variable names are stored (e.g., `KLING_ACCESS_KEY`). Actual values are resolved via `config()` at runtime, which works correctly after `config:cache`.

### Webhook authentication

The webhook endpoint (`POST /api/webhook/{jobId}`) has no Sanctum auth (vendors call it externally). Security relies on UUID unpredictability (the jobId is a UUID that only the vendor and the system know) plus optional HMAC-SHA256 signature verification via the `webhook_secret` field on the config.

---

## Queue System

### Queue channels

```
ai_generation    → AiGenerationJob
                   Sends the initial request to the vendor API
                   Retries: 3, backoff: [10s, 30s, 60s], timeout: 300s

ai_polling       → AiPollingJob
                   Polls vendor status with progressive backoff (10→20→40→60s)
                   Self-re-dispatches until completion, failure, or 20-min timeout
                   Retries: 3 (for HTTP errors), timeout: 60s

                 → AssetDownloadJob
                   Downloads vendor assets to local storage
                   Retries: 3, backoff: [15s, 30s, 60s], timeout: 300s

                 → WebhookTimeoutJob
                   Safety net for callback mode — marks job as failed if no callback
                   Dispatched with 20-minute delay, runs once (tries: 1)

notifications    → AiJobCompletedPushNotification (via event listener)
                 → SendPushNotification
                   Delivers FCM notifications to user devices
```

### Queue driver

Default: `database` (uses the `jobs` and `failed_jobs` tables). Can be switched to Redis by setting `QUEUE_CONNECTION=redis` in `.env`.

### Retry and failure handling

Jobs that throw exceptions are retried according to their `$tries` and `$backoff` settings. After all retries are exhausted, the `failed()` method runs — this updates the `ai_jobs` record to `status: failed` and logs the error.

The polling job uses a special pattern: instead of `release()` (which conflicts with the tries count), it dispatches a fresh `AiPollingJob` for the next poll cycle. This way `$tries` only applies to genuine exceptions, not to the polling loop itself.

---

## Asset Storage Pipeline

When a generation completes (detected by polling or webhook), the system downloads vendor assets to local storage before notifying the user:

```
Vendor CDN URL (temporary, expires in 24-72h)
       │
  AssetDownloadJob
       │
       ├── Downloads video → storage/app/public/generations/{user_id}/{job_id}/video_0_abc.mp4
       ├── Downloads thumbnail → storage/app/public/generations/{user_id}/{job_id}/thumb_0_xyz.jpg
       │
       ├── Updates ai_jobs.result with permanent local URLs
       ├── Keeps original vendor URLs as fallback (original_url field)
       │
       └── Fires AiJobCompletedEvent (with local URLs in the notification)

Permanent URL served via storage symlink:
  https://king-ai.nxtgenitpark.com/storage/generations/{user_id}/{job_id}/video_0_abc.mp4
```

If the download fails after retries, the system still fires the event with the original vendor URLs so the user isn't left waiting — degraded experience (expiring URLs) is better than no notification.

The `CleanupGenerations` command (`php artisan generations:cleanup --days=30`) deletes old assets from disk and marks job records as cleaned up.

---

## Push Notification System

```
Event fired (e.g., AiJobCompletedEvent)
       │
  EventServiceProvider routes to listener
       │
  AiJobCompletedPushNotification (queued on 'notifications')
       │
       ├── Looks up user's active device tokens from 'devices' table
       ├── Builds notification title/body based on job status
       ├── Builds data payload: { type, action, job_id, feature }
       │
  FCMPushService
       │
       ├── Creates Firebase Messaging instance from service account JSON
       ├── Sends multicast message to all user's device tokens (batched at 500)
       └── Auto-deactivates invalid tokens on delivery failure
```

Three event types trigger notifications: `AiJobCompletedEvent` (generation completed/failed), `TemplateEvent` (template CRUD), and `CategoryEvent` (category CRUD).

The push service is registered as a binding in `AppServiceProvider` via `PushNotificationServiceInterface`, allowing easy swapping of the FCM implementation.

---

## Admin Panel (Filament)

The Filament 3 admin panel at `/admin` provides full CRUD management. Access is gated by `User::canAccessPanel()` which requires the `access-admin-panel` permission — non-admin users get a 403 even with valid credentials.

**DataSourceResource** — manage vendor definitions. **AiFeatureResource** — manage feature types and their active vendor. **AiFeatureConfigResource** — the most complex resource, with preset loading for Kling, structured JSON editors for schema/template/mapping, conditional JWT fields, completion mode selector, and webhook URL preview. **TemplateResource** — template management with content uploads (repeater), metadata editor (description, output options, input fields repeater with sample image references, user guide). **TemplateCategoryResource** — category management. **UserResource** — user management with Role select (single-value via `relationship('roles', 'name')`), Role badge column, role/verified/2FA filters, and row actions for resending verification codes and regenerating temp passwords. **VendorStatusMappingResource** — vendor status string mapping.

**Auth-related custom pages:** `EmailVerify` (`/admin/email-verify`), `ChangePassword` (`/admin/change-password`), `TwoFactorChallenge` (`/admin/two-factor-challenge`), `TwoFactorSettings` (`/admin/2fa-settings`), and `Auth/ForgotPassword` (`/admin/forgot-password`, linked from the login screen via `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER`). The first three are gate destinations for the auth pipeline middlewares; the last two are self-service flows.

The `AiFeatureConfigResource` includes a "Load Preset" action that auto-fills all fields for known vendor configurations (Kling image2video, text2video, effects), including the three-section response mapping and callback completion mode.

---

## API Layer

### Controllers

`AuthController` — register, login, logout, device registration. Login enforces the five-step priority gate (credentials → email verified → must_change_password → 2FA → token). `EmailVerificationController` — `/email/verify` and `/email/resend` for the 6-digit code flow. `PasswordChangeController` — forced password change for admin-created users with temp passwords. `PasswordResetController` — forgot-password flow (issues reset codes, accepts code + new password). `TwoFactorController` — setup, confirm, status, recovery code regeneration, disable, and the unauthenticated challenge endpoint that exchanges challenge_token + code for a Sanctum token. `UserController` — CRUD for users, `updateRoles` for role assignment with self-demotion guard. `CategoryController` — CRUD for template categories with data source attachment. `TemplateController` — CRUD for templates with metadata and content uploads, auto-fills data_source_id from ai_feature. `AiFeatureController` — CRUD for AI features. `AiFeatureConfigController` — type-specific create/update for static token and JWT configs, auth type switching. `AiController` — generation endpoint (queues job) and status endpoint (returns normalized response, scoped to auth()->id()). `HistoryController` — generation history list/detail/download/hide, all scoped to auth()->id(). `UploadController` — file upload with MIME verification and size limits. `VendorStatusMappingController` — CRUD for vendor status mappings. `WebhookController` — receives vendor callbacks, normalizes via GenericPollStrategy, dispatches AssetDownloadJob.

### Form Requests

Each create/update endpoint has dedicated FormRequest classes for validation: `RegisterRequest`, `LoginRequest`, `DeviceRegisterRequest`, `StoreTemplateRequest`, `UpdateTemplateRequest`, `StoreAiFeatureRequest`, `UpdateAiFeatureRequest`, `StoreStaticTokenConfigRequest`, `StoreJwtConfigRequest`, `UpdateStaticTokenConfigRequest`, `UpdateJwtConfigRequest`, `SwitchAuthTypeRequest`, `StoreVendorStatusMappingRequest`, `UpdateVendorStatusMappingRequest`, `StoreUserRequest`, `UpdateUserRequest`.

### API Resources

`TemplateResource` — transforms templates with resolved metadata (content index references converted to URLs). `CategoryCollection` / `CategoryResource` — nested template/category/config output. `UserResource` — user data formatting. `AiFeatureConfigAdminResource` — config details for admin API. `AiFeatureResource` — feature with config count.

### Response format

All API responses use a consistent structure via `ResponseTrait`:
```json
{
  "success": true|false,
  "data": { ... },
  "message": "Human-readable message",
  "errors": null|{ ... }
}
```

---

## Directory Structure

```
app/
├── Console/Commands/           CleanupGenerations, ViduTemplateImport, ViduTemplateCategoryImport
├── Contracts/                  AuthStrategyInterface, PollStrategyInterface
├── Events/                     AiJobCompletedEvent, CategoryEvent, TemplateEvent
├── Exceptions/                 Handler (unified API error formatting)
├── Filament/
│   ├── Pages/                  EmailVerify, ChangePassword, TwoFactorChallenge,
│   │                           TwoFactorSettings, Auth/ForgotPassword
│   └── Resources/              7 Filament resources with Pages/ and RelationManagers/
├── Http/
│   ├── Controllers/            16 controllers (incl. AuthController, EmailVerificationController,
│   │                           PasswordChangeController, PasswordResetController,
│   │                           TwoFactorController, WebhookController)
│   ├── Middleware/             EnsureEmailVerified, EnsureMustChangePassword,
│   │                           EnsureTwoFactorChallenge
│   ├── Requests/               18+ form request validators
│   └── Resources/              6 API resource transformers
├── Jobs/                       AiGenerationJob, AiPollingJob, AssetDownloadJob,
│                               WebhookTimeoutJob, SendPushNotification
├── Listeners/                  AiJobCompletedPushNotification,
│                               TemplateEventPushNotification,
│                               CategoryEventPushNotification
├── Models/                     User, AiFeature, AiFeatureConfig, AiJob, DataSource, Device,
│                               EmailVerificationCode, PasswordResetCode, Template,
│                               TemplateCategory, TemplateCategoryDataSource, TemplateContent,
│                               VendorStatusMapping
├── Notifications/              VerifyEmailNotification, TemporaryPasswordNotification,
│                               PasswordResetNotification (all ShouldQueue)
├── Providers/                  AppServiceProvider, EventServiceProvider,
│                               Filament/AdminPanelProvider
├── Services/                   AiGenerationService, AuthStrategyResolver, PollStrategyResolver,
│                               FCMPushService, PushNotificationServiceInterface,
│                               EmailVerificationService, PasswordChangeService,
│                               PasswordResetService, TwoFactorService, UserService
├── Strategies/
│   ├── AuthStrategy/           JwtAuthStrategy, StaticTokenAuthStrategy
│   └── GenericPollStrategy     (single config-driven poll strategy for all vendors)
└── Traits/                     ResponseTrait

config/
├── ai.php                      Default vendor setting
├── cors.php                    CORS (handled by Nginx, minimal Laravel config)
├── firebase.php                Firebase project configuration
├── livewire.php                Upload settings (100MB, allowed MIME types)
├── permission.php              spatie/laravel-permission config (teams=false)
├── push.php                    Push notification provider setting
├── vendor-credentials.php      Vendor API keys (env variable mapping)
└── [standard Laravel configs]

database/migrations/             34 migrations in chronological order
                                 Including 2026_05_04_* batch for 2FA, email verification,
                                 must_change_password, password reset, and permission tables
database/seeders/                Admin, DataSource, TemplateCategories, AiFeatures,
                                 RolesAndPermissionsSeeder

routes/
├── api.php                     All API routes (auth, 2FA, email verify, password reset,
│                               CRUD, generation, history, webhook). Permission middleware
│                               applied per endpoint group.
├── web.php                     Minimal (welcome page)
└── console.php                 Artisan commands

storage/app/
├── public/templates/            Admin-uploaded sample images and preview videos
├── public/generations/          Downloaded vendor assets (organized by user/job)
├── livewire-tmp/                Temporary Livewire upload staging
└── vidio-king-ai-firebase.json  Firebase service account credentials
```

---

## Configuration System

### Key principle: `config()` not `env()`

After `php artisan config:cache`, `env()` returns null everywhere except inside config files. All application code uses `config()` to read values. Vendor API credentials are mapped through `config/vendor-credentials.php`:

```php
// config/vendor-credentials.php
return [
    'KLING_ACCESS_KEY' => env('KLING_ACCESS_KEY'),
    'KLING_SECRET_KEY' => env('KLING_SECRET_KEY'),
    'VIDU_API_KEY' => env('VIDU_API_KEY'),
];

// Usage in auth strategies:
$token = config('vendor-credentials.VIDU_API_KEY');
```

The `ai_feature_configs` table stores the **name** of the env variable (e.g., `KLING_ACCESS_KEY`), not the value. The auth strategy resolves it via `config('vendor-credentials.' . $config->auth_value_from_env)`.

### Config caching

After any change to `.env` or config files: `php artisan config:cache`. After any route changes: `php artisan route:cache`. After any code changes: `pm2 restart all` (queue workers cache code in memory).

### Scheduled tasks

Schedules are registered in `bootstrap/app.php` via `->withSchedule(...)`. Laravel 12 does not load `app/Console/Kernel.php` — that file should not exist in this project. Currently scheduled:

- `generations:cleanup --days=30` — daily at 03:00, with `withoutOverlapping()` and `onOneServer()`. Removes locally-stored vendor assets older than 30 days and updates the matching `ai_jobs.result` records to flag them as cleaned up.

Cron must invoke `php artisan schedule:run` every minute on the VPS for any of this to fire:

```
* * * * * cd /var/www/king-ai && php artisan schedule:run >> /dev/null 2>&1
```

---

## Error Handling

The custom `Handler` class in `App\Exceptions` provides unified API error responses. It is wired up in `AppServiceProvider::register()` by binding `Illuminate\Contracts\Debug\ExceptionHandler` to `App\Exceptions\Handler`.

All API exceptions are caught and formatted into the standard `{ success, data, message, errors }` structure. Specific exception types map to appropriate HTTP status codes: `ValidationException` → 422, `AuthenticationException` → 401, `ModelNotFoundException` → 404, `MethodNotAllowedHttpException` → 405. In debug mode, error responses include exception class, file, line, and a truncated stack trace.

Generation failures are handled at multiple levels: `AiGenerationJob::failed()` marks the job as failed in the database, `AiPollingJob::failed()` does the same for polling failures, and `AssetDownloadJob::failed()` still fires the completion event so the user gets notified even if asset download fails. The webhook timeout job marks callback-mode jobs as failed if no callback arrives within 20 minutes.