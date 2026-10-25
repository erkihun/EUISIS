<?php

use App\Actions\ChangeCardStatus;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoreReadController;
use App\Http\Controllers\Api\CoreWriteController;
use App\Http\Controllers\Api\EmployeeCardController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StructureController;
use App\Settings\CoreSettingsRegistry;
use Illuminate\Support\Facades\Route;

/*
 * The Core platform API, for EUISIS and partner systems (the Core's own
 * screens are the web routes). Read routes need any active Core account; write routes need
 * an officer or admin role; settings need an admin. Every route applies the
 * caller's organization scope. Rate limits come from Core settings › Security
 * (AppServiceProvider).
 */
Route::get('/', fn () => response()->json(['service' => config('app.name'), 'api' => url('/api/v1'), 'health' => url('/up')]));
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
        Route::get('/lookups', [StructureController::class, 'lookups']);
        Route::get('/hierarchy', [StructureController::class, 'hierarchy']);
        Route::get('/{kind}', [StructureController::class, 'types'])->whereIn('kind', ['organization-types', 'unit-types']);
        Route::get('/positions/{position}', [PositionController::class, 'show']);
        Route::get('/card-requests', [EmployeeCardController::class, 'requests']);

        // Officers and administrators change master data; every action checks scope itself.
        Route::middleware('core.user:writer')->group(function (): void {
            Route::post('/{kind}', [StructureController::class, 'saveType'])->whereIn('kind', ['organization-types', 'unit-types']);
            Route::put('/{kind}/{id}', [StructureController::class, 'saveType'])->whereIn('kind', ['organization-types', 'unit-types']);
            Route::post('/organizations', [StructureController::class, 'storeOrganization']);
            Route::put('/organizations/{organization}', [StructureController::class, 'updateOrganization']);
            Route::post('/organization-units', [StructureController::class, 'storeUnit']);
            Route::put('/organization-units/{unit}', [StructureController::class, 'updateUnit']);

            Route::post('/positions', [PositionController::class, 'store']);
            Route::put('/positions/{position}', [PositionController::class, 'update']);
            Route::post('/positions/{position}/establishments', [PositionController::class, 'approveEstablishment']);
            Route::post('/establishments/{establishment}/end', [PositionController::class, 'endEstablishment']);

            Route::post('/employees', [CoreWriteController::class, 'registerEmployee']);
            Route::put('/employees/{employee}', [EmployeeCardController::class, 'updateEmployee']);
            Route::post('/employees/{employee}/status', [EmployeeCardController::class, 'changeStatus']);
            Route::post('/employees/{employee}/transfer', [CoreWriteController::class, 'transfer']);
            Route::post('/employees/{employee}/card-requests', [EmployeeCardController::class, 'submitRequest']);

            Route::post('/card-requests/{cardRequest}/{decision}', [EmployeeCardController::class, 'decide'])->whereIn('decision', ['approve', 'reject', 'cancel']);
            Route::post('/id-cards/{card}/replace', [EmployeeCardController::class, 'replace']);
            Route::post('/id-cards/{card}/{transition}', [CoreWriteController::class, 'changeCard'])->whereIn('transition', ChangeCardStatus::transitions());
        });

        Route::middleware('core.user:admin')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::put('/settings/{group}', [SettingsController::class, 'update'])->whereIn('group', CoreSettingsRegistry::GROUPS);
            Route::get('/users', [AdminController::class, 'users']);
            Route::post('/users', [AdminController::class, 'saveUser']);
            Route::put('/users/{user}', [AdminController::class, 'saveUser']);
            Route::post('/users/{user}/reset-password', [AdminController::class, 'resetPassword']);
            Route::get('/data-quality', [AdminController::class, 'dataQuality']);
        });
    });
});
