<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AiFeatureConfigController;
use App\Http\Controllers\AiFeatureController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TemplateController;
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
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
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
