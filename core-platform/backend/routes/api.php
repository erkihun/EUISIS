<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoreReadController;
use App\Http\Controllers\Api\CoreWriteController;
use App\Http\Controllers\Api\SettingsController;
use App\Settings\CoreSettingsRegistry;
use Illuminate\Support\Facades\Route;

/*
 * The Core platform API, used by the Core frontend and, later, by EUISIS and
 * partner systems. Read routes need any active Core account; write routes need
 * an officer or admin role; settings need an admin. Every route applies the
 * caller's organization scope. Rate limits come from Core settings › Security
 * (AppServiceProvider).
 */
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::get('/settings/public', [SettingsController::class, 'public'])->middleware('throttle:120,1');

Route::middleware(['auth:sanctum', 'core.user', 'throttle:core-api'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::prefix('v1')->group(function (): void {
        Route::get('/dashboard', [CoreReadController::class, 'dashboard']);
        Route::get('/organizations', [CoreReadController::class, 'organizations']);
        Route::get('/organizations/{organization}', [CoreReadController::class, 'organization']);
        Route::get('/organization-units', [CoreReadController::class, 'units']);
        Route::get('/positions', [CoreReadController::class, 'positions']);
        Route::get('/employees', [CoreReadController::class, 'employees']);
        Route::get('/employees/{employee}', [CoreReadController::class, 'employee']);
        Route::get('/assignments', [CoreReadController::class, 'assignments']);
        Route::get('/id-cards', [CoreReadController::class, 'cards']);
        Route::post('/cards/verify', [CoreReadController::class, 'verifyCard'])->middleware('throttle:core-verify');
        Route::get('/audit', [CoreReadController::class, 'audit']);

        Route::middleware('core.user:writer')->group(function (): void {
            Route::post('/employees', [CoreWriteController::class, 'registerEmployee']);
            Route::post('/employees/{employee}/transfer', [CoreWriteController::class, 'transfer']);
            Route::post('/id-cards/{card}/{transition}', [CoreWriteController::class, 'changeCard'])->whereIn('transition', ['suspend', 'reinstate', 'revoke']);
        });

        Route::middleware('core.user:admin')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::put('/settings/{group}', [SettingsController::class, 'update'])->whereIn('group', CoreSettingsRegistry::GROUPS);
        });
    });
});
