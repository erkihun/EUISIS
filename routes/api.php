<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CardVerificationController;
use App\Http\Controllers\Api\V1\EmployeeDirectoryApiController;
use App\Http\Controllers\Api\V1\EmployeeEntitlementController;
use App\Http\Controllers\Api\V1\IdCardVerificationApiController;
use App\Http\Controllers\Api\V1\NfcController;
use App\Http\Controllers\Api\V1\OfflineSyncController;
use App\Http\Controllers\Api\V1\OrganizationApiController;
use App\Http\Controllers\Api\V1\OrganizationDirectoryController;
use App\Http\Controllers\Api\V1\OrganizationUnitApiController;
use App\Http\Controllers\Api\V1\PositionApiController;
use App\Http\Controllers\Api\V1\ProviderSettlementController;
use App\Http\Controllers\Api\V1\ServiceAuthorizationController;
use App\Http\Controllers\Api\V1\ServiceTransactionController;
use App\Http\Controllers\Api\V1\Transport\BoardingController as TransportBoardingController;
use App\Http\Controllers\Api\V1\Transport\DeviceController as TransportDeviceController;
use App\Http\Controllers\Api\V1\Transport\PositionController as TransportPositionController;
use App\Http\Controllers\Api\V1\Transport\TripController as TransportTripController;
use App\Http\Middleware\NfcApplicationGate;
use Illuminate\Support\Facades\Route;

/*
 * Transport scanner devices (docs/transport/device-security.md). Each device
 * authenticates with its own revocable credential; it never names a provider,
 * route or vehicle of its own — the server resolves all authority.
 */
Route::middleware(['transport.device', 'throttle:transport-device'])->prefix('v1/transport')->name('api.transport.')->group(function (): void {
    Route::get('/device', [TransportDeviceController::class, 'show'])->name('device.show');
    Route::post('/device/heartbeat', [TransportDeviceController::class, 'heartbeat'])->name('device.heartbeat');
    Route::get('/trips/today', [TransportTripController::class, 'today'])->name('trips.today');
    Route::get('/trips/{trip}', [TransportTripController::class, 'show'])->name('trips.show');
    Route::post('/trips/{trip}/start', [TransportTripController::class, 'start'])->name('trips.start');
    Route::post('/trips/{trip}/depart', [TransportTripController::class, 'depart'])->name('trips.depart');
    Route::post('/trips/{trip}/complete', [TransportTripController::class, 'complete'])->name('trips.complete');
    Route::post('/boardings/verify', [TransportBoardingController::class, 'verify'])->name('boardings.verify');
    Route::post('/boardings/sync', [TransportBoardingController::class, 'sync'])->name('boardings.sync');
    Route::post('/trips/{trip}/positions', [TransportPositionController::class, 'store'])->name('positions.store');
});

Route::middleware(['auth:sanctum', 'throttle:api', NfcApplicationGate::class, 'api.external'])->prefix('v1/nfc')->name('api.v1.nfc.')->group(function (): void {
    Route::post('/challenges', [NfcController::class, 'challenge'])->middleware('api.scope:nfc.verify')->name('challenges');
    Route::post('/verify', [NfcController::class, 'verify'])->middleware('api.scope:nfc.verify')->name('verify');
    Route::post('/service-eligibility', [NfcController::class, 'eligibility'])->middleware('api.scope:nfc.service_eligibility')->name('eligibility');
    Route::post('/service-transactions/verify-and-record', [NfcController::class, 'record'])->middleware('api.scope:nfc.service_transactions.create')->name('record');
});

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
    //
    // Registered before the organization-data group below so it keeps serving
    // GET /api/v1/organizations for the integrations already assigned to it.
    // Moving that path to the new controller would silently change the payload
    // and the required scope for every existing caller.
    Route::get('/organizations', [OrganizationDirectoryController::class, 'index'])
        ->middleware('api.scope:reports.read_limited')
        ->name('api.v1.organizations.index');

    /*
     * Organization → unit → position → assignment → employee reads.
     *
     * Each route asserts its own scope, and the surrounding `api.external`
     * gate independently requires the endpoint to be assigned to the calling
     * application, so a token holding the scope still cannot call an endpoint
     * an administrator has not granted it.
     */
    // `/organizations/directory` rather than `/organizations`: that path is
    // already taken by the directory endpoint above, whose payload and scope
    // existing integrations depend on. A distinct path is the only way to add
    // the richer, filterable, paginated listing without changing what a
    // currently-assigned caller receives.
    Route::get('/organizations/directory', [OrganizationApiController::class, 'index'])
        ->middleware('api.scope:organizations.read')
        ->name('api.v1.organizations.directory');
    Route::get('/organizations/{organization}', [OrganizationApiController::class, 'show'])
        ->middleware('api.scope:organizations.read')
        ->name('api.v1.organizations.show');
    Route::get('/organizations/{organization}/units', [OrganizationApiController::class, 'units'])
        ->middleware('api.scope:organization_units.read')
        ->name('api.v1.organizations.units');
    Route::get('/organizations/{organization}/positions', [OrganizationApiController::class, 'positions'])
        ->middleware('api.scope:positions.read')
        ->name('api.v1.organizations.positions');
    Route::get('/organizations/{organization}/employees', [OrganizationApiController::class, 'employees'])
        ->middleware('api.scope:employees.basic_read')
        ->name('api.v1.organizations.employees');
    Route::get('/organizations/{organization}/structure', [OrganizationApiController::class, 'structure'])
        ->middleware('api.scope:organization_structure.read')
        ->name('api.v1.organizations.structure');

    Route::get('/organization-units', [OrganizationUnitApiController::class, 'index'])
        ->middleware('api.scope:organization_units.read')
        ->name('api.v1.organization-units.index');
    Route::get('/organization-units/{unit}', [OrganizationUnitApiController::class, 'show'])
        ->middleware('api.scope:organization_units.read')
        ->name('api.v1.organization-units.show');
    Route::get('/organization-units/{unit}/positions', [OrganizationUnitApiController::class, 'positions'])
        ->middleware('api.scope:positions.read')
        ->name('api.v1.organization-units.positions');
    Route::get('/organization-units/{unit}/employees', [OrganizationUnitApiController::class, 'employees'])
        ->middleware('api.scope:employees.basic_read')
        ->name('api.v1.organization-units.employees');

    Route::get('/positions', [PositionApiController::class, 'index'])
        ->middleware('api.scope:positions.read')
        ->name('api.v1.positions.index');
    Route::get('/positions/{position}', [PositionApiController::class, 'show'])
        ->middleware('api.scope:positions.read')
        ->name('api.v1.positions.show');

    Route::get('/employees', [EmployeeDirectoryApiController::class, 'index'])
        ->middleware('api.scope:employees.basic_read')
        ->name('api.v1.employees.index');
    Route::get('/employees/{employee}', [EmployeeDirectoryApiController::class, 'show'])
        ->middleware('api.scope:employees.basic_read')
        ->name('api.v1.employees.show');
    Route::get('/employees/{employee}/assignment', [EmployeeDirectoryApiController::class, 'assignment'])
        ->middleware('api.scope:employee_assignments.read')
        ->name('api.v1.employees.assignment');
});
