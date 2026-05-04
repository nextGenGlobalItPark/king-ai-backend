<?php

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
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);

    Route::post('register-device', [AuthController::class, 'registerDevice']);

    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::post('/', [CategoryController::class, 'store']);
        Route::get('/{id}', [CategoryController::class, 'show']);
        Route::put('/{id}', [CategoryController::class, 'update']);
        Route::delete('/{id}', [CategoryController::class, 'destroy']);
        Route::delete('/{template_category_id}/data-sources/{datasource_id}', [CategoryController::class, 'detachDataSource']);
    });

    Route::apiResource('templates', TemplateController::class);

    Route::apiResource('ai-features', AiFeatureController::class);
    Route::prefix('ai-feature-configs')->group(function () {

        // List & Show (unchanged)
        Route::get('/', [AiFeatureConfigController::class, 'index']);
        Route::get('/{aiFeatureConfig}', [AiFeatureConfigController::class, 'show']);

        // Create - Type-specific
        Route::post('/static-token', [AiFeatureConfigController::class, 'storeStaticToken']);
        Route::post('/jwt', [AiFeatureConfigController::class, 'storeJwt']);

        // Update - Type-specific
        Route::put('/{aiFeatureConfig}/static-token', [AiFeatureConfigController::class, 'updateStaticToken']);
        Route::put('/{aiFeatureConfig}/jwt', [AiFeatureConfigController::class, 'updateJwt']);

        // Switch auth type
        Route::patch('/{aiFeatureConfig}/switch-auth', [AiFeatureConfigController::class, 'switchAuthType']);

        // Delete (unchanged)
        Route::delete('/{aiFeatureConfig}', [AiFeatureConfigController::class, 'destroy']);
    });

    // AI generation - tighter rate limit (10 per minute per user)
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/generate/{feature}', [AiController::class, 'generate']);
    });
    Route::get('/status/{jobId}', [AiController::class, 'status']);

    Route::post('/upload', [UploadController::class, 'upload']);

    // Generation history
    Route::prefix('history')->group(function () {
        Route::get('/', [HistoryController::class, 'index']);
        Route::get('/{jobId}', [HistoryController::class, 'show']);
        Route::get('/{jobId}/download', [HistoryController::class, 'download']);
        Route::delete('/{jobId}', [HistoryController::class, 'destroy']);
    });

    Route::prefix('task-status')->group(function () {
        Route::get('/', [VendorStatusMappingController::class, 'index']);
        Route::post('/', [VendorStatusMappingController::class, 'store']);
        Route::get('/{vendorStatusMapping}', [VendorStatusMappingController::class, 'show']);
        Route::put('/{vendorStatusMapping}', [VendorStatusMappingController::class, 'update']);
        Route::delete('/{vendorStatusMapping}', [VendorStatusMappingController::class, 'destroy']);

    });
});
