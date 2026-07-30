<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ZoneController;
use App\Http\Controllers\InternetServiceController;
use App\Http\Controllers\MikrotikRouterController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->apiResource('clients', ClientController::class);
Route::middleware('auth:sanctum')->apiResource('zones', ZoneController::class)->except('destroy');
Route::middleware('auth:sanctum')->apiResource('plans', PlanController::class)->except('destroy');
Route::middleware('auth:sanctum')->apiResource('mikrotik-routers', MikrotikRouterController::class)->except('destroy');
Route::middleware('auth:sanctum')->group(function () {
    Route::post('mikrotik-routers/{mikrotik_router}/test-connection', [MikrotikRouterController::class, 'testConnection']);
    Route::apiResource('services', InternetServiceController::class)->only(['index', 'store', 'show']);
    Route::post('services/{service}/suspend', [InternetServiceController::class, 'suspend']);
    Route::post('services/{service}/reactivate', [InternetServiceController::class, 'reactivate']);
    Route::put('services/{service}/plan', [InternetServiceController::class, 'changePlan']);
});
