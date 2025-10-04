<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AiFeatureConfigController;
use App\Http\Controllers\AiFeatureController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::prefix('auth')->group(function () {
    Route::post('/register', [UserController::class, 'register']);
    Route::post('/login', [UserController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);

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
    Route::apiResource('ai-feature-configs', AiFeatureConfigController::class);

    Route::post('/generate/{feature}', [AiController::class, 'generate']);
//    Route::post('/generate/template/{template-id}', [AiController::class, 'generateWithTemplate']);
    Route::get('/status/{jobId}', [AiController::class, 'status']);
});