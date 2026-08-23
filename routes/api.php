<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CardVerificationController;
use App\Http\Controllers\Api\V1\EmployeeEntitlementController;
use App\Http\Controllers\Api\V1\IdCardVerificationApiController;
use App\Http\Controllers\Api\V1\OfflineSyncController;
use App\Http\Controllers\Api\V1\OrganizationDirectoryController;
use App\Http\Controllers\Api\V1\ProviderSettlementController;
use App\Http\Controllers\Api\V1\ServiceAuthorizationController;
use App\Http\Controllers\Api\V1\ServiceTransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'provider.scope'])->prefix('v1')->group(function (): void {
    Route::post('/cards/verify', CardVerificationController::class)
        ->middleware('api.scope:id_cards.verify')
        ->name('api.v1.cards.verify');
    Route::post('/services/{serviceType}/authorize', ServiceAuthorizationController::class)
        ->middleware('api.scope:service_eligibility.check')
        ->name('api.v1.services.authorize');
    Route::post('/services/{serviceType}/transactions', ServiceTransactionController::class)
        ->middleware(['api.scope:service_transactions.create', 'api.idempotency'])
        ->name('api.v1.services.transactions');
    Route::get('/employees/{employee}/entitlements', EmployeeEntitlementController::class)
        ->middleware('api.scope:service_eligibility.check')
        ->name('api.v1.employees.entitlements');
    Route::get('/providers/{provider}/settlements/{period}', ProviderSettlementController::class)
        ->middleware('api.scope:reports.read_limited')
        ->name('api.v1.providers.settlements');
    Route::post('/offline-sync/transactions', OfflineSyncController::class)
        ->middleware(['api.scope:service_transactions.create', 'api.idempotency'])
        ->name('api.v1.offline-sync.transactions');
});

/*
 * Integration endpoints for approved external applications. Separate from the
 * provider-portal group above because these callers are not service providers
 * and must not pass through `provider.scope`.
 */
Route::middleware(['auth:sanctum', 'throttle:api', 'api.external'])->prefix('v1')->group(function (): void {
    Route::get('/id-cards/verify/{token}', [IdCardVerificationApiController::class, 'show'])
        ->middleware('api.scope:id_cards.verify')
        ->name('api.v1.id-cards.verify');
    Route::get('/employees/{employee}/service-eligibility', [IdCardVerificationApiController::class, 'eligibility'])
        ->middleware('api.scope:service_eligibility.check')
        ->name('api.v1.employees.service-eligibility');

    // Read-only directory so integrations can pick a real organization rather
    // than have an operator type its code by hand.
    Route::get('/organizations', [OrganizationDirectoryController::class, 'index'])
        ->middleware('api.scope:reports.read_limited')
        ->name('api.v1.organizations.index');
});
