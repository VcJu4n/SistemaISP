<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ZoneController;
use App\Http\Controllers\InternetServiceController;
use App\Http\Controllers\MikrotikImportController;
use App\Http\Controllers\MikrotikRouterController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\NotificationTemplateController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ServiceDueDateController;
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
    Route::get('dashboard/billing-summary', [ReportController::class, 'billingDashboard']);
    Route::get('clients/{client}/payments', [PaymentController::class, 'clientPayments']);
    Route::get('clients/{client}/notification-logs', [NoticeController::class, 'clientLogs']);
    Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
    Route::get('notices/kanban', [NoticeController::class, 'kanban']);
    Route::get('notices/summary', [NoticeController::class, 'summary']);
    Route::post('notices/{service}/whatsapp', [NoticeController::class, 'sendWhatsapp']);
    Route::apiResource('notification-templates', NotificationTemplateController::class)->only(['index', 'update']);
    Route::post('mikrotik-routers/{mikrotik_router}/test-connection', [MikrotikRouterController::class, 'testConnection']);
    Route::post('mikrotik-routers/{mikrotik_router}/detect-control-method', [MikrotikImportController::class, 'detect']);
    Route::post('mikrotik-routers/{mikrotik_router}/import-candidates/sync', [MikrotikImportController::class, 'sync']);
    Route::get('mikrotik-routers/{mikrotik_router}/import-candidates', [MikrotikImportController::class, 'index']);
    Route::post('mikrotik-import-candidates/{candidate}/link', [MikrotikImportController::class, 'link']);
    Route::post('mikrotik-import-candidates/{candidate}/create-client', [MikrotikImportController::class, 'createClient']);
    Route::post('mikrotik-import-candidates/{candidate}/ignore', [MikrotikImportController::class, 'ignore']);
    Route::apiResource('services', InternetServiceController::class)->only(['index', 'store', 'show']);
    Route::post('services/{service}/suspend', [InternetServiceController::class, 'suspend']);
    Route::post('services/{service}/reactivate', [InternetServiceController::class, 'reactivate']);
    Route::put('services/{service}/due-date', [ServiceDueDateController::class, 'update']);
    Route::put('services/{service}/plan', [InternetServiceController::class, 'changePlan']);
    Route::put('services/{service}/technical-config', [InternetServiceController::class, 'updateTechnicalConfig']);
});
