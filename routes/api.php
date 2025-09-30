<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\ViduTemplateController;
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

    Route::prefix('template/vidu')->group(function () {
        Route::get('/', [ViduTemplateController::class, 'index']);
        Route::post('/', [ViduTemplateController::class, 'store']);
        Route::get('/{templateId}', [ViduTemplateController::class, 'show']);
        Route::put('/{templateId}', [ViduTemplateController::class, 'update']);
        Route::delete('/{templateId}', [ViduTemplateController::class, 'destroy']);
    });

    Route::prefix('template')->group(function () {
        Route::get('/', [TemplateController::class, 'index']);
        Route::post('/', [TemplateController::class, 'store']);
        Route::get('/{id}', [TemplateController::class, 'show']);
        Route::put('/{id}', [TemplateController::class, 'update']);
        Route::delete('/{id}', [TemplateController::class, 'destroy']);
    });
});