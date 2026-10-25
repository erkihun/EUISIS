<?php

use App\Actions\ChangeCardStatus;
use App\Actions\ManageHierarchyVersion;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoreReadController;
use App\Http\Controllers\Api\CoreWriteController;
use App\Http\Controllers\Api\EmployeeCardController;
use App\Http\Controllers\Api\HierarchyVersionController;
use App\Http\Controllers\Api\IntegrationAdminController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StructureController;
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

/*
 * The integration API for the systems that consume the Core: an API client's
 * token, one scope per route, the client's organizations, its own rate limit
 * and a log row per call (EnsureApiClient). Read-only plus the card check;
 * external systems never write Core master data (core-platform/docs/integration-api.md).
 */
Route::prefix('v1/integration')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/organizations', [IntegrationController::class, 'organizations'])->middleware('core.client:organizations:read');
    Route::get('/organizations/{organization}', [IntegrationController::class, 'organization'])->middleware('core.client:organizations:read');
    Route::get('/positions', [IntegrationController::class, 'positions'])->middleware('core.client:positions:read');
    Route::get('/positions/{position}', [IntegrationController::class, 'position'])->middleware('core.client:positions:read');
    Route::get('/employees', [IntegrationController::class, 'employees'])->middleware('core.client:employees:basic-read');
    Route::get('/employees/{employee}', [IntegrationController::class, 'employee'])->middleware('core.client:employees:basic-read');
    Route::get('/employees/{employee}/assignment', [IntegrationController::class, 'assignment'])->middleware('core.client:assignments:read');
    Route::get('/employees/{employee}/assignment-history', [IntegrationController::class, 'assignmentHistory'])->middleware('core.client:assignments:read');
    Route::get('/employees/{employee}/status', [IntegrationController::class, 'employeeStatus'])->middleware('core.client:employee-status:read');
    Route::post('/cards/verify', [IntegrationController::class, 'verifyCard'])->middleware('core.client:cards:verify');
});

Route::middleware(['auth:sanctum', 'core.user', 'throttle:core-api'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1');

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
        Route::get('/employees/{employee}/card-eligibility', [EmployeeCardController::class, 'eligibility']);

        // Officers and administrators change master data; every action checks scope itself.
        Route::middleware('core.user:writer')->group(function (): void {
            Route::post('/{kind}', [StructureController::class, 'saveType'])->whereIn('kind', ['organization-types', 'unit-types']);
            Route::put('/{kind}/{id}', [StructureController::class, 'saveType'])->whereIn('kind', ['organization-types', 'unit-types']);
            Route::post('/organizations', [StructureController::class, 'storeOrganization']);
            Route::put('/organizations/{organization}', [StructureController::class, 'updateOrganization']);
            Route::get('/organizations/{organization}/usage', [StructureController::class, 'organizationUsage']);
            Route::delete('/organizations/{organization}', [StructureController::class, 'deleteOrganization']);
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

        // The Integration Center: API clients, webhooks, deliveries, events and call logs.
        Route::middleware('core.user:city-admin')->group(function (): void {
            Route::get('/api-clients', [IntegrationAdminController::class, 'clients']);
            Route::post('/api-clients', [IntegrationAdminController::class, 'storeClient']);
            Route::put('/api-clients/{apiClient}', [IntegrationAdminController::class, 'updateClient']);
            Route::post('/api-clients/{apiClient}/rotate', [IntegrationAdminController::class, 'rotateClient']);
            Route::post('/api-clients/{apiClient}/revoke', [IntegrationAdminController::class, 'revokeClient']);
            Route::get('/api-logs', [IntegrationAdminController::class, 'logs']);
            Route::get('/webhooks', [IntegrationAdminController::class, 'webhooks']);
            Route::post('/webhooks', [IntegrationAdminController::class, 'storeWebhook']);
            Route::put('/webhooks/{webhook}', [IntegrationAdminController::class, 'updateWebhook']);
            Route::post('/webhooks/{webhook}/rotate-secret', [IntegrationAdminController::class, 'rotateWebhook']);
            Route::get('/webhook-deliveries', [IntegrationAdminController::class, 'deliveries']);
            Route::post('/webhook-deliveries/{delivery}/retry', [IntegrationAdminController::class, 'retryDelivery']);
            Route::get('/integration-events', [IntegrationAdminController::class, 'events']);
        });

        // The governed hierarchy lifecycle is the city's: city-wide administrators only.
        Route::middleware('core.user:city-admin')->prefix('hierarchy-versions')->group(function (): void {
            Route::get('/', [HierarchyVersionController::class, 'index']);
            Route::post('/', [HierarchyVersionController::class, 'store']);
            Route::get('/{hierarchyVersion}', [HierarchyVersionController::class, 'show']);
            Route::put('/{hierarchyVersion}/parent', [HierarchyVersionController::class, 'changeParent']);
            Route::post('/{hierarchyVersion}/{transition}', [HierarchyVersionController::class, 'transition'])->whereIn('transition', ManageHierarchyVersion::TRANSITIONS);
        });

        Route::middleware('core.user:admin')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'index'])->middleware('core.user:city-admin');
            Route::put('/settings/{group}', [SettingsController::class, 'update'])->whereIn('group', CoreSettingsRegistry::GROUPS)->middleware('core.user:city-admin');
            Route::get('/users', [AdminController::class, 'users']);
            Route::post('/users', [AdminController::class, 'saveUser']);
            Route::put('/users/{user}', [AdminController::class, 'saveUser']);
            Route::post('/users/{user}/reset-password', [AdminController::class, 'resetPassword']);
            Route::get('/data-quality', [AdminController::class, 'dataQuality'])->middleware('core.user:city-admin');
        });
    });
});
