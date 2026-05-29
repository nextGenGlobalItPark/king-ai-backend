# Vidio King — API & Setup Instructions

This document is the working API reference and setup guide. For high-level design, see `Architecture.md`. For deployment, see `Deployment.md`. For an exhaustive feature-by-feature description, see `FEATURES.md`.

## Table of Contents

- [Project Structure](#project-structure)
- [Authentication Overview](#authentication-overview)
- [Response Envelope](#response-envelope)
- [Authentication Endpoints](#authentication-endpoints)
- [Email Verification](#email-verification)
- [Password Management](#password-management)
- [Two-Factor Authentication](#two-factor-authentication)
- [OAuth2 — Google & Apple Sign-In](#oauth2--google--apple-sign-in)
- [User Management (admin-only)](#user-management-admin-only)
- [Catalog Endpoints](#catalog-endpoints)
- [AI Generation Endpoints](#ai-generation-endpoints)
- [History](#history)
- [File Upload](#file-upload)
- [Webhook (Vendor Callback)](#webhook-vendor-callback)
- [API Usage Examples](#api-usage-examples)
- [Permission Reference](#permission-reference)
- [Environment Variables](#environment-variables)
- [Installation](#installation)
- [Common Tasks](#common-tasks)
- [Testing](#testing)

---

## Project Structure

```
king-ai-backend/
├── app/                         Application core
│   ├── Console/Commands/        Artisan commands (CleanupGenerations, etc.)
│   ├── Contracts/               AuthStrategyInterface, PollStrategyInterface
│   ├── Events/                  AiJobCompletedEvent, CategoryEvent, TemplateEvent
│   ├── Filament/                Pages and Resources for the admin panel
│   ├── Http/
│   │   ├── Controllers/         16 controllers
│   │   ├── Middleware/          EnsureEmailVerified, EnsureMustChangePassword,
│   │   │                        EnsureTwoFactorChallenge
│   │   ├── Requests/            Form request validators
│   │   └── Resources/           API resource transformers
│   ├── Jobs/                    AiGenerationJob, AiPollingJob, AssetDownloadJob, etc.
│   ├── Listeners/               Push notification dispatchers
│   ├── Models/                  Eloquent models
│   ├── Notifications/           VerifyEmailNotification, TemporaryPasswordNotification,
│   │                            PasswordResetNotification (all queued)
│   ├── Providers/               AppServiceProvider, EventServiceProvider, AdminPanelProvider
│   ├── Services/                Business logic (AiGenerationService, EmailVerificationService,
│   │                            PasswordChangeService, PasswordResetService, TwoFactorService,
│   │                            UserService, FCMPushService, etc.)
│   └── Strategies/              Vendor auth strategies and the GenericPollStrategy
├── config/                      Configuration files (incl. permission.php, vendor-credentials.php)
├── database/
│   ├── migrations/              34 migrations (incl. the 2026_05_04_* auth/onboarding/permissions batch)
│   └── seeders/                 RolesAndPermissionsSeeder, AdminSeeder, etc.
├── public/                      Publicly accessible files
├── resources/                   Views (Filament pages, mail layouts)
├── routes/                      api.php, web.php, console.php
├── storage/                     Logs, generated assets, Firebase service account
└── tests/                       Test files
```

### Database Models

`User`, `EmailVerificationCode`, `PasswordResetCode`, `OauthAccount`, `Device`, `AiFeature`, `AiFeatureConfig`, `AiJob`, `Template`, `TemplateCategory`, `TemplateContent`, `TemplateCategoryDataSource`, `DataSource`, `VendorStatusMapping`, plus the `spatie/laravel-permission` models (`Role`, `Permission`).

---

## Authentication Overview

The API uses Laravel Sanctum bearer tokens. Send `Authorization: Bearer {token}` on every authenticated request.

**Tokens are NOT issued by `/auth/register`.** New users must verify their email via the 6-digit code flow first, then call `/auth/login` to obtain a token. This is a deliberate two-step to prevent token issuance to unverified emails.

**Login can return one of three success shapes** depending on user state:

| Condition | Response shape |
|---|---|
| Must change password (admin-created user) | 200 with `change_token` + `requires_password_change: true` |
| 2FA is enabled | 200 with `challenge_token` + `requires_two_factor: true` |
| Otherwise | 200 with `token` (the Sanctum bearer) |

`challenge_token` is a random UUID stored in Laravel Cache with a **5-minute TTL** (not a signed JWT). It is single-use — consuming it with a correct TOTP code pulls it from the cache and returns a Sanctum token. On a wrong code, a fresh `challenge_token` is returned in `errors.challenge_token` so the user does not need to re-enter their password.

Always check for `requires_password_change` and `requires_two_factor` in your client's login handler.

---

## Response Envelope

All API responses use the standard structure:

```json
{
  "success": true,
  "data": { ... },
  "message": "Human-readable message",
  "errors": null
}
```

On failure, `success` is `false`, `data` is null, and `errors` carries field-level validation errors (when applicable). HTTP status codes:

- 200 — OK (read or successful action)
- 201 — Created
- 401 — Unauthenticated (missing or invalid token)
- 403 — Permission denied OR email not verified OR similar gate failure
- 404 — Not found (also returned for resources the user doesn't own — no ownership leak)
- 422 — Validation failed (`errors` populated)
- 429 — Rate limit exceeded

---

## Authentication Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/auth/register` | none | Create a new user (assigns `user` role; sends verification code; **no token returned**) |
| `POST` | `/api/auth/login` | none | Authenticate; returns one of three success shapes (see above) |
| `POST` | `/api/auth/logout` | sanctum | Revoke the current bearer token |

---

## Email Verification

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/auth/email/verify` | none | Submit `{email, code}` to mark email as verified |
| `POST` | `/api/auth/email/resend` | none | Request a fresh code (60-second cooldown per email) |

**Code mechanics:** 6 digits, 15-minute expiry, max 5 verification attempts before the code is invalidated.

---

## Password Management

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/auth/password/forgot` | none | Issue a 6-digit reset code via email |
| `POST` | `/api/auth/password/reset` | none | Submit `{email, code, new_password, new_password_confirmation}` to reset |
| `POST` | `/api/auth/password/change-initial` | none (uses change_token) | For admin-created users on first login: submit `{change_token, new_password, new_password_confirmation}` to clear `must_change_password`. **Issues a Sanctum token directly** — no need to call `/auth/login` afterward. |

The reset endpoint also clears `must_change_password` if it was set. Neither endpoint issues a Sanctum token — the user must log in afterward.

> **Note on `change-initial`:** unlike the reset flow, `/auth/password/change-initial` **does** issue a Sanctum token directly on success. The client receives `{token: "..."}` and is immediately authenticated without a separate `/auth/login` call.

---

## Two-Factor Authentication

Available to all authenticated users, both `admin` and `user` roles. Setup endpoints require a current Sanctum token; the challenge endpoint is unauthenticated and uses the short-lived `challenge_token` returned by login.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/two-factor/setup` | sanctum | Begin setup; returns QR code data URI + secret |
| `POST` | `/api/two-factor/confirm` | sanctum | Submit a TOTP code from the authenticator app to activate; returns 8 recovery codes (shown once) |
| `GET` | `/api/two-factor/status` | sanctum | Check if 2FA is enabled and confirmed |
| `POST` | `/api/two-factor/recovery-codes` | sanctum | Regenerate recovery codes (requires password reconfirmation) |
| `DELETE` | `/api/two-factor` | sanctum | Disable 2FA (requires password reconfirmation) |
| `POST` | `/api/two-factor/challenge` | none | Exchange `{challenge_token, code}` for a Sanctum token. `code` can be either a TOTP code or a recovery code. Tighter rate limit (10 req/min). |

Recovery codes are single-use — consumed codes are removed from the user's encrypted blob.

---

## OAuth2 — Google & Apple Sign-In

Social sign-in via Google and Apple, brokered through Supabase Auth. The backend owns the full PKCE flow — clients just open a browser/WebView to the redirect URL and receive a Sanctum token on the deep link.

### Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/api/auth/oauth/{provider}` | none | Initiate OAuth flow — redirects browser to Supabase. `{provider}` is `google` or `apple`. |
| `GET` | `/api/auth/oauth/{provider}/callback` | none | Supabase callback — exchanges code, issues Sanctum token, redirects to mobile deep link. |

### Success redirect

```
vidioking://auth/callback?token={sanctum_token}
```

The mobile app intercepts this deep link, reads `token`, and stores it as the bearer token for all subsequent API calls.

### Error redirect

```
vidioking://auth/callback?error={message}
```

Triggered when the user cancels, the OAuth state expires (10 min), or Supabase rejects the code exchange.

### Account behaviour

- **New user:** account auto-created with `role=user`, email verified, no password.
- **Existing email match:** existing account merged — `oauth_accounts` row linked; email marked verified if not already.
- **Returning user:** looked up via `oauth_accounts.provider_id` — no duplicate account created.
- **2FA:** not challenged for OAuth logins.
- **`must_change_password`:** always `false` for OAuth users; gate skipped.

### 2FA restrictions for OAuth-only users

Users with no password (OAuth-only) cannot call:

| Endpoint | Response |
|---|---|
| `DELETE /api/two-factor` | `403` — "This operation is not available for accounts signed in via Google or Apple." |
| `POST /api/two-factor/recovery-codes` | `403` — same message |

### Example — mobile OAuth flow

```
1. App opens WebView to:
   GET https://api.vidioking.com/api/auth/oauth/google

2. Browser follows redirects through Supabase → Google consent screen

3. After consent, Supabase redirects to your callback URL;
   Laravel exchanges the code and redirects to:
   vidioking://auth/callback?token=1|XyZ123abc...

4. App intercepts the deep link, extracts the token, stores it,
   and calls any authenticated endpoint normally:
   Authorization: Bearer 1|XyZ123abc...
```

### Required Supabase dashboard setup

1. Enable **Google** and **Apple** providers under Authentication → Providers
2. Add these URLs to Authentication → URL Configuration → Redirect URLs:
   - `{APP_URL}/api/auth/oauth/google/callback`
   - `{APP_URL}/api/auth/oauth/apple/callback`
   - `{APP_URL}/admin/oauth/google/callback`
   - `{APP_URL}/admin/oauth/apple/callback`

### Required `.env` variables

```ini
SUPABASE_URL=https://xyzxyz.supabase.co
SUPABASE_ANON_KEY=eyJ...
OAUTH_MOBILE_REDIRECT_SCHEME=vidioking
```

---

## User Management (admin-only)

All endpoints require `permission:manage-users` (admins only).

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/users` | List users |
| `GET` | `/api/users/{id}` | Get user by ID |
| `POST` | `/api/users` | Create a user (auto-generates temp password, sends two emails, returns plaintext temp password once) |
| `PUT` | `/api/users/{id}` | Update user |
| `DELETE` | `/api/users/{id}` | Soft-delete user |
| `PUT` | `/api/users/{id}/roles` | Replace user's roles. Body `{"roles": ["admin"]}`. Self-demotion guard prevents the requesting admin from removing their own admin role. |

`POST /api/users` accepts an optional `role` field (`"admin"` or `"user"`, defaults to `"user"`).

---

## Catalog Endpoints

Read access (`GET`) is open to both `admin` and `user`. Write access (POST/PUT/PATCH/DELETE) requires the corresponding `manage-*` permission (admin only).

### Categories
| Method | Path | Permission |
|---|---|---|
| `GET` | `/api/categories` | view-categories |
| `GET` | `/api/categories/{id}` | view-categories |
| `POST` | `/api/categories` | manage-categories |
| `PUT` | `/api/categories/{id}` | manage-categories |
| `DELETE` | `/api/categories/{id}` | manage-categories |
| `DELETE` | `/api/categories/{template_category_id}/data-sources/{datasource_id}` | manage-categories |

### Templates
`GET /api/templates`, `GET /api/templates/{template}` — view-templates.
`POST /api/templates`, `PUT /api/templates/{template}`, `PATCH /api/templates/{template}`, `DELETE /api/templates/{template}` — manage-templates.

### AI Features
`GET /api/ai-features`, `GET /api/ai-features/{ai_feature}` — view-ai-features.
`POST /api/ai-features`, `PUT/PATCH /api/ai-features/{ai_feature}`, `DELETE /api/ai-features/{ai_feature}` — manage-ai-features.

### AI Feature Configs
`GET /api/ai-feature-configs`, `GET /api/ai-feature-configs/{aiFeatureConfig}` — view-ai-feature-configs.

The write endpoints are auth-type-specific:
- `POST /api/ai-feature-configs/static-token` — create static token config
- `POST /api/ai-feature-configs/jwt` — create JWT config
- `PUT /api/ai-feature-configs/{aiFeatureConfig}/static-token` — update
- `PUT /api/ai-feature-configs/{aiFeatureConfig}/jwt` — update
- `PATCH /api/ai-feature-configs/{aiFeatureConfig}/switch-auth` — switch auth type in place
- `DELETE /api/ai-feature-configs/{aiFeatureConfig}` — delete

All write endpoints require `manage-ai-feature-configs`.

### Vendor Status Mappings (admin-only)
All `/api/task-status/*` endpoints require `manage-task-status`. Standard CRUD shape.

---

## AI Generation Endpoints

| Method | Path | Permission | Purpose |
|---|---|---|---|
| `POST` | `/api/generate/{feature}` | generate-content | Queue a generation job. `{feature}` is the AiFeature slug. Returns `{job_id}`. Rate-limited to 10 req/min per user. |
| `GET` | `/api/status/{jobId}` | generate-content | Check job status. Scoped to `auth()->id()`. |
| `POST` | `/api/upload` | generate-content | Upload reference assets for generation |
| `POST` | `/api/register-device` | manage-own-devices | Register/refresh FCM device token |

Both `admin` and `user` roles have these permissions, so admins can also generate content with their own account. Status queries are user-scoped, so admins see only their own jobs through the API.

**Race condition note:** if your client polls `/api/status/{jobId}` immediately after `/api/generate`, the AiJob row may not exist yet. The endpoint returns `status: queued` (not 404) until the queue worker creates the row, so the polling loop just continues normally.

---

## History

All endpoints require `permission:view-own-history`. Queries are scoped to `auth()->id()` — even admins see only their own jobs through the API.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/history` | List user's jobs (paginated) |
| `GET` | `/api/history/{jobId}` | Job detail |
| `GET` | `/api/history/{jobId}/download` | Stream the asset to the client |
| `DELETE` | `/api/history/{jobId}` | Soft-hide (sets `hidden_at`); does not delete the row |

For cross-user history visibility, admins use Filament instead.

---

## File Upload

`POST /api/upload` (requires `generate-content`).

Multipart form data with a `file` field. Used to upload reference images for image-to-video and similar features. Stored under `storage/app/public/uploads/{user_id}/`. Limits: 100MB max (PHP and Nginx tuned in deployment configs).

---

## Webhook (Vendor Callback)

`POST /api/webhook/{jobId}` — unauthenticated. Vendors call this when generation completes (in webhook completion mode). `{jobId}` is the internal `queue_job_id` UUID. Security: UUID unpredictability + optional HMAC-SHA256 signature verification via the config's `webhook_secret`.

---

## API Usage Examples

### 1. Register, verify, log in

```http
POST /api/auth/register
Content-Type: application/json

{
  "name": "Saifur Rahman",
  "email": "saifur@example.com",
  "password": "secret123",
  "password_confirmation": "secret123"
}
```

```json
HTTP/1.1 201 Created
{
  "success": true,
  "data": {
    "user": {
      "id": 42,
      "name": "Saifur Rahman",
      "email": "saifur@example.com",
      "email_verified_at": null,
      "roles": ["user"]
    },
    "requires_email_verification": true,
    "email": "saifur@example.com"
  },
  "message": "Account created. Check your email for the 6-digit verification code."
}
```

The user receives an email with a 6-digit code. Submit it:

```http
POST /api/auth/email/verify
Content-Type: application/json

{
  "email": "saifur@example.com",
  "code": "428193"
}
```

Then log in:

```http
POST /api/auth/login
Content-Type: application/json

{
  "email": "saifur@example.com",
  "password": "secret123"
}
```

```json
HTTP/1.1 200 OK
{
  "success": true,
  "data": {
    "user": { "id": 42, "name": "...", "email": "...", "roles": ["user"] },
    "token": "1|XyZ123abc..."
  },
  "message": "Logged in."
}
```

### 2. Login when 2FA is enabled

```http
POST /api/auth/login
{ "email": "...", "password": "..." }
```

```json
HTTP/1.1 200 OK
{
  "success": true,
  "data": {
    "challenge_token": "550e8400-e29b-41d4-a716-446655440000",
    "requires_two_factor": true
  },
  "message": "Two-factor authentication required."
}
```

```http
POST /api/two-factor/challenge
{
  "challenge_token": "550e8400-e29b-41d4-a716-446655440000",
  "code": "847291"
}
```

```json
HTTP/1.1 200 OK
{
  "success": true,
  "data": {
    "user": { ... },
    "token": "1|XyZ123abc..."
  }
}
```

The `code` field accepts either a 6-digit TOTP code from the authenticator app or a 10-character recovery code (single-use).

### 3. Login when password change is required (admin-created user)

```http
POST /api/auth/login
{ "email": "...", "password": "TempPass123XYZ" }
```

```json
HTTP/1.1 200 OK
{
  "success": true,
  "data": {
    "change_token": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
    "requires_password_change": true
  },
  "message": "You must change your temporary password before continuing."
}
```

```http
POST /api/auth/password/change-initial
{
  "change_token": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
  "new_password": "myNewPassword",
  "new_password_confirmation": "myNewPassword"
}
```

```json
HTTP/1.1 200 OK
{
  "success": true,
  "data": {
    "token": "1|XyZ123abc..."
  },
  "message": "Password updated. You are now logged in."
}
```

`change-initial` issues a Sanctum token directly — the client is authenticated immediately and does not need a separate `/auth/login` call.

### 4. Set up 2FA

```http
POST /api/two-factor/setup
Authorization: Bearer 1|XyZ...
```

```json
{
  "success": true,
  "data": {
    "qr_code": "data:image/svg+xml;base64,...",
    "secret": "JBSWY3DPEHPK3PXP"
  }
}
```

The user scans the QR with their authenticator app, gets a 6-digit code, and submits:

```http
POST /api/two-factor/confirm
Authorization: Bearer 1|XyZ...

{ "code": "428193" }
```

```json
{
  "success": true,
  "data": {
    "recovery_codes": [
      "abc12-def34", "xyz98-pqr76", "..."
    ]
  },
  "message": "Two-factor authentication enabled. Save your recovery codes — they will not be shown again."
}
```

### 5. Generate AI content

```http
POST /api/generate/image2video
Authorization: Bearer 1|XyZ...
Content-Type: application/json

{
  "input": {
    "image": "https://api.example.com/storage/uploads/1/abc.jpg",
    "prompt": "A cat walking through a forest"
  },
  "template_id": 3
}
```

```json
HTTP/1.1 200 OK
{
  "success": true,
  "data": {
    "job_id": "550e8400-e29b-41d4-a716-446655440000",
    "vendor": 1
  },
  "message": "Generation queued."
}
```

Poll status:

```http
GET /api/status/550e8400-e29b-41d4-a716-446655440000
Authorization: Bearer 1|XyZ...
```

```json
{
  "success": true,
  "data": {
    "job_id": "550e8400-...",
    "status": "completed",
    "progress": 100,
    "feature": "image2video",
    "result": {
      "videos": [
        { "url": "https://king-ai.example.com/storage/generations/42/550e8400-.../video.mp4" }
      ]
    }
  }
}
```

The video URL is permanent (downloaded to local storage). The vendor's original CDN URL is kept as a fallback in `original_url` if needed.

### 6. Permission-denied response

When a `user`-role user calls a `manage-*` endpoint:

```http
POST /api/templates
Authorization: Bearer 1|XyZ... (user role)
```

```json
HTTP/1.1 403 Forbidden
{
  "success": false,
  "data": null,
  "message": "You do not have permission to perform this action.",
  "errors": null
}
```

---

## Permission Reference

Full matrix of who can do what. Both roles are seeded against both `web` (Filament) and `sanctum` (API) guards.

| Permission | admin | user | Gates |
|---|---|---|---|
| `access-admin-panel` | ✓ | | Filament `canAccessPanel()` |
| `manage-users`, `view-users` | ✓ | | All `/api/users/*` |
| `view-templates` | ✓ | ✓ | `GET /api/templates*` |
| `manage-templates` | ✓ | | Template write |
| `view-categories` | ✓ | ✓ | `GET /api/categories*` |
| `manage-categories` | ✓ | | Category write |
| `view-ai-features` | ✓ | ✓ | `GET /api/ai-features*` |
| `manage-ai-features` | ✓ | | AI Feature write |
| `view-ai-feature-configs` | ✓ | ✓ | `GET /api/ai-feature-configs*` |
| `manage-ai-feature-configs` | ✓ | | AI Feature Config write |
| `view-data-sources`, `manage-data-sources` | ✓ | | (reserved, no API endpoints today) |
| `manage-task-status` | ✓ | | All `/api/task-status/*` |
| `generate-content` | ✓ | ✓ | `/api/generate`, `/api/status`, `/api/upload` |
| `view-own-history` | ✓ | ✓ | `/api/history*` |
| `view-all-history` | ✓ | | (reserved, no API endpoint today) |
| `manage-own-devices` | ✓ | ✓ | `/api/register-device` |

---

## Environment Variables

Copy `.env.example` to `.env` and configure:

```ini
# App
APP_NAME=VidioKing
APP_ENV=local
APP_KEY=                          # generate via `php artisan key:generate`
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vidio_king
DB_USERNAME=root
DB_PASSWORD=

# Mail (Gmail SMTP example)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-account@gmail.com
MAIL_PASSWORD=                    # Gmail App Password (16 chars, no spaces)
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-account@gmail.com  # MUST match MAIL_USERNAME for Gmail
MAIL_FROM_NAME="${APP_NAME}"

# Queue
QUEUE_CONNECTION=database

# Sanctum (for SPA cookie auth, ignored by mobile bearer flow)
SANCTUM_STATEFUL_DOMAINS=localhost

# Firebase (FCM push notifications)
FIREBASE_CREDENTIALS=/path/to/storage/app/vidio-king-ai-firebase.json
FIREBASE_PROJECT_ID=

# Supabase (OAuth2 — Google & Apple sign-in)
SUPABASE_URL=https://xyzxyz.supabase.co
SUPABASE_ANON_KEY=eyJ...
OAUTH_MOBILE_REDIRECT_SCHEME=vidioking     # mobile deep link scheme
OAUTH_WEB_REDIRECT_URL=                    # reserved, not yet used

# Vendor credentials (mapped through config/vendor-credentials.php)
KLING_ACCESS_KEY=
KLING_SECRET_KEY=
VIDU_API_KEY=
```

Email setup gotchas:
- Gmail requires 2-Step Verification on the Google account before App Passwords can be created
- `MAIL_FROM_ADDRESS` must equal `MAIL_USERNAME` — Gmail rejects mismatched From headers
- Switching to SendGrid or Mailgun is `.env`-only (no code change)

---

## Installation

1. **Clone**
   ```bash
   git clone <repo>
   cd king-ai-backend
   ```

2. **Dependencies**
   ```bash
   composer install
   npm install && npm run build
   ```

3. **Environment**
   ```bash
   cp .env.example .env
   # edit .env with your DB, mail, vendor credentials
   php artisan key:generate
   ```

4. **Database**
   ```bash
   php artisan migrate
   php artisan db:seed --class=RolesAndPermissionsSeeder
   ```
   > The `2026_05_26_*` migrations make `users.password` nullable and create the `oauth_accounts` table. Both are required for OAuth to work.

5. **Storage symlink**
   ```bash
   php artisan storage:link
   ```

6. **Filament admin user (first admin)**
   ```bash
   php artisan tinker
   >>> $u = \App\Models\User::create([
   ...     'name' => 'You',
   ...     'email' => 'you@example.com',
   ...     'password' => bcrypt('changeme'),
   ...     'email_verified_at' => now(),
   ... ]);
   >>> $u->assignRole('admin');
   >>> exit
   ```

7. **Run**
   ```bash
   php artisan serve              # dev server
   php artisan queue:work          # foreground worker (use PM2 in production)
   ```

8. **Cron** (production only — needed for `generations:cleanup`)
   ```
   * * * * * cd /var/www/king-ai && php artisan schedule:run >> /dev/null 2>&1
   ```

---

## Common Tasks

### Adding a new admin
```bash
php artisan tinker
>>> User::where('email', 'newadmin@example.com')->first()->syncRoles(['admin']);
```

### Backfilling roles after first deploying the permissions feature
```bash
php artisan tinker
>>> User::find(1)->assignRole('admin');
>>> User::whereDoesntHave('roles')->each(fn($u) => $u->assignRole('user'));
```

### Resetting a user's 2FA when they lost their device
Either via the Filament UserResource row action ("Reset 2FA"), or:
```bash
php artisan tinker
>>> $u = User::find(42);
>>> $u->forceFill([
...     'two_factor_secret' => null,
...     'two_factor_recovery_codes' => null,
...     'two_factor_confirmed_at' => null,
... ])->save();
```

### Re-syncing roles/permissions after editing the seeder
```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan permission:cache-reset
```

### Cleaning up old generated assets
```bash
php artisan generations:cleanup --days=30
```

### Clearing caches after deploy
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan permission:cache-reset
sudo systemctl restart php8.2-fpm        # OPcache
pm2 restart all                          # queue workers must be restarted to pick up new code
```

The PM2 restart is non-negotiable — workers cache code in memory and bugs that have been fixed will appear to recur if you skip it.

---

## Testing

```bash
php artisan test
```

Tests use `RefreshDatabase` so each suite runs against a clean schema. The roles/permissions seeder must be re-run inside the test setup if your tests need permission gates to pass:

```php
public function setUp(): void
{
    parent::setUp();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
}
```