<?php

use App\Http\Controllers\OauthController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AiFeatureConfigController;
use App\Http\Controllers\AiFeatureController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\VendorStatusMappingController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\HistoryController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| Vendor Webhook (no auth — called by external AI vendors)
|--------------------------------------------------------------------------
|
| This route receives callbacks from AI vendors (Kling, etc.) when a
| generation task completes. No Sanctum auth since the vendor server
| calls it. Security relies on UUID unpredictability + optional
| webhook_secret signature verification in the controller.
|
*/
Route::post('/webhook/{jobId}', [WebhookController::class, 'handle'])
    ->middleware('throttle:60,1') // Rate limit: 60 per minute to prevent abuse
    ->name('webhook.handle');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    /*
    |--------------------------------------------------------------------------
    | OAuth2 — Google / Apple via Supabase PKCE
    |--------------------------------------------------------------------------
    |
    | /oauth/{provider}           — initiates the OAuth flow; redirects to
    |                               Supabase which handles Google/Apple.
    | /oauth/{provider}/callback  — receives the auth code from Supabase,
    |                               exchanges it, issues a Sanctum token, and
    |                               redirects to the mobile deep link:
    |                               vidioking://auth/callback?token={token}
    |
    | Both routes are unauthenticated — the browser (or in-app WebView)
    | follows the redirect chain and the token comes back on the deep link.
    | 2FA is NOT challenged for OAuth sign-ins per product spec.
    |
    */
    Route::prefix('oauth')->group(function () {
        Route::get('/{provider}', [OauthController::class, 'redirect'])
            ->name('oauth.redirect');
        Route::get('/{provider}/callback', [OauthController::class, 'callback'])
            ->name('oauth.callback');
    });

    /*
    |--------------------------------------------------------------------------
    | Email Verification (public — user has no token until verified)
    |--------------------------------------------------------------------------
    |
    | /email/verify  — { email, code } → marks user as verified.
    | /email/resend  — { email } → sends a new 6-digit code.
    |
    | Both throttled tighter than normal auth: brute-forcing the 6-digit
    | space (1M combinations) would otherwise be feasible. The service
    | layer additionally caps attempts per code at 5 and applies a 60s
    | resend cooldown, but the route-level throttle is what stops a
    | distributed enumeration probe.
    |
    */
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:5,1');

    /*
    |--------------------------------------------------------------------------
    | Forced password change (after admin-created user logs in with temp pw)
    |--------------------------------------------------------------------------
    |
    | Public — accepts a one-time change_token issued by /auth/login when
    | must_change_password is true. Exchanges the token for a real sanctum
    | token after the user supplies a new password.
    |
    */
    Route::post('/password/change-initial', [PasswordChangeController::class, 'changeInitial'])
        ->middleware('throttle:5,1');

    /*
    |--------------------------------------------------------------------------
    | Forgot Password (public — code-based reset for verified users)
    |--------------------------------------------------------------------------
    |
    | /password/forgot — { email } → sends a 6-digit reset code.
    | /password/reset  — { email, code, new_password } → applies new pw.
    |
    | Both throttled the same way as email verification (brute-force the
    | 6-digit code is otherwise feasible). Service layer additionally
    | caps attempts at 5 and applies a 60s per-user cooldown.
    |
    | Note: this flow does NOT bypass 2FA. After reset, the user still
    | logs in via /auth/login and goes through the 2FA challenge if
    | enabled. Reset only changes the password.
    |
    */
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:5,1');
    Route::post('/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| Two-Factor Authentication
|--------------------------------------------------------------------------
|
| /two-factor/challenge is unauthenticated — it accepts a challenge_token
| issued by /auth/login when the user has 2FA enabled, plus a TOTP code
| or recovery code, and exchanges them for a real sanctum token.
|
| Setup/management endpoints (setup, confirm, status, recovery-codes,
| disable) require sanctum auth: the user is already logged in and
| managing their own 2FA. Destructive operations additionally require
| password reconfirmation in the request body.
|
*/
Route::prefix('two-factor')->group(function () {
    // Challenge — exchange challenge_token + code for sanctum token.
    // Throttled tighter than normal auth to slow brute-force on the OTP.
    Route::post('/challenge', [TwoFactorController::class, 'challenge'])
        ->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/status', [TwoFactorController::class, 'status']);
        Route::post('/setup', [TwoFactorController::class, 'setup']);
        Route::post('/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::delete('/', [TwoFactorController::class, 'disable']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | User management — admin-only
    |--------------------------------------------------------------------------
    |
    | All five endpoints require manage-users. There's no read-only
    | distinction here because exposing the user list to non-admins would
    | leak emails and account state.
    |
    */
    Route::prefix('users')->middleware('permission:manage-users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::post('/', [UserController::class, 'store']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
        Route::put('/{id}/roles', [UserController::class, 'updateRoles']);
    });

    /*
    |--------------------------------------------------------------------------
    | Devices — every authenticated user manages their own
    |--------------------------------------------------------------------------
    */
    Route::post('register-device', [AuthController::class, 'registerDevice'])
        ->middleware('permission:manage-own-devices');

    /*
    |--------------------------------------------------------------------------
    | Categories — read for everyone, write for admins
    |--------------------------------------------------------------------------
    */
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])
            ->middleware('permission:view-categories');
        Route::get('/{id}', [CategoryController::class, 'show'])
            ->middleware('permission:view-categories');

        Route::post('/', [CategoryController::class, 'store'])
            ->middleware('permission:manage-categories');
        Route::put('/{id}', [CategoryController::class, 'update'])
            ->middleware('permission:manage-categories');
        Route::delete('/{id}', [CategoryController::class, 'destroy'])
            ->middleware('permission:manage-categories');
        Route::delete('/{template_category_id}/data-sources/{datasource_id}', [CategoryController::class, 'detachDataSource'])
            ->middleware('permission:manage-categories');
    });

    /*
    |--------------------------------------------------------------------------
    | Templates — read for everyone, write for admins
    |--------------------------------------------------------------------------
    */
    Route::prefix('templates')->group(function () {
        Route::get('/', [TemplateController::class, 'index'])
            ->middleware('permission:view-templates');
        Route::get('/{template}', [TemplateController::class, 'show'])
            ->middleware('permission:view-templates');

        Route::post('/', [TemplateController::class, 'store'])
            ->middleware('permission:manage-templates');
        Route::put('/{template}', [TemplateController::class, 'update'])
            ->middleware('permission:manage-templates');
        Route::patch('/{template}', [TemplateController::class, 'update'])
            ->middleware('permission:manage-templates');
        Route::delete('/{template}', [TemplateController::class, 'destroy'])
            ->middleware('permission:manage-templates');
    });

    /*
    |--------------------------------------------------------------------------
    | AI Features — read for everyone, write for admins
    |--------------------------------------------------------------------------
    */
    Route::prefix('ai-features')->group(function () {
        Route::get('/', [AiFeatureController::class, 'index'])
            ->middleware('permission:view-ai-features');
        Route::get('/{ai_feature}', [AiFeatureController::class, 'show'])
            ->middleware('permission:view-ai-features');

        Route::post('/', [AiFeatureController::class, 'store'])
            ->middleware('permission:manage-ai-features');
        Route::put('/{ai_feature}', [AiFeatureController::class, 'update'])
            ->middleware('permission:manage-ai-features');
        Route::patch('/{ai_feature}', [AiFeatureController::class, 'update'])
            ->middleware('permission:manage-ai-features');
        Route::delete('/{ai_feature}', [AiFeatureController::class, 'destroy'])
            ->middleware('permission:manage-ai-features');
    });

    /*
    |--------------------------------------------------------------------------
    | AI Feature Configs — read for everyone, write for admins
    |--------------------------------------------------------------------------
    */
    Route::prefix('ai-feature-configs')->group(function () {
        Route::get('/', [AiFeatureConfigController::class, 'index'])
            ->middleware('permission:view-ai-feature-configs');
        Route::get('/{aiFeatureConfig}', [AiFeatureConfigController::class, 'show'])
            ->middleware('permission:view-ai-feature-configs');

        // Type-specific create
        Route::post('/static-token', [AiFeatureConfigController::class, 'storeStaticToken'])
            ->middleware('permission:manage-ai-feature-configs');
        Route::post('/jwt', [AiFeatureConfigController::class, 'storeJwt'])
            ->middleware('permission:manage-ai-feature-configs');

        // Type-specific update
        Route::put('/{aiFeatureConfig}/static-token', [AiFeatureConfigController::class, 'updateStaticToken'])
            ->middleware('permission:manage-ai-feature-configs');
        Route::put('/{aiFeatureConfig}/jwt', [AiFeatureConfigController::class, 'updateJwt'])
            ->middleware('permission:manage-ai-feature-configs');

        // Switch auth type
        Route::patch('/{aiFeatureConfig}/switch-auth', [AiFeatureConfigController::class, 'switchAuthType'])
            ->middleware('permission:manage-ai-feature-configs');

        Route::delete('/{aiFeatureConfig}', [AiFeatureConfigController::class, 'destroy'])
            ->middleware('permission:manage-ai-feature-configs');
    });

    /*
    |--------------------------------------------------------------------------
    | AI generation, upload, status, history — every user can use these
    | for their OWN data. Ownership is enforced at the controller level
    | (queries scoped to auth()->id()), so a user can't poll someone
    | else's job by guessing job IDs.
    |--------------------------------------------------------------------------
    */

    // AI generation - tighter rate limit (10 per minute per user)
    Route::middleware(['throttle:10,1', 'permission:generate-content'])->group(function () {
        Route::post('/generate/{feature}', [AiController::class, 'generate']);
    });
    Route::get('/status/{jobId}', [AiController::class, 'status'])
        ->middleware('permission:generate-content');

    Route::post('/upload', [UploadController::class, 'upload'])
        ->middleware('permission:generate-content');

    // Generation history — all four require view-own-history. The
    // HistoryController scopes queries by user_id at the model layer,
    // so users see only their own jobs even though the route doesn't
    // distinguish.
    Route::prefix('history')->middleware('permission:view-own-history')->group(function () {
        Route::get('/', [HistoryController::class, 'index']);
        Route::get('/{jobId}', [HistoryController::class, 'show']);
        Route::get('/{jobId}/download', [HistoryController::class, 'download']);
        Route::delete('/{jobId}', [HistoryController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Vendor Status Mappings — admin-only catalog
    |--------------------------------------------------------------------------
    */
    Route::prefix('task-status')->middleware('permission:manage-task-status')->group(function () {
        Route::get('/', [VendorStatusMappingController::class, 'index']);
        Route::post('/', [VendorStatusMappingController::class, 'store']);
        Route::get('/{vendorStatusMapping}', [VendorStatusMappingController::class, 'show']);
        Route::put('/{vendorStatusMapping}', [VendorStatusMappingController::class, 'update']);
        Route::delete('/{vendorStatusMapping}', [VendorStatusMappingController::class, 'destroy']);
    });
});
