<?php

declare(strict_types=1);

/*
 * Classes outside a module that still use its internals, frozen when the module
 * moved (ADR 0017). ModulePublicApiTest ignores exactly these for that module and
 * fails when one no longer needs it, so the list can only shrink: replace a use
 * with the module's Contracts and delete the line.
 *
 * Providers (2026-10-30): Transport and Cafeteria hold provider records in
 * Eloquent relations and type hints, and their portal controllers pass the
 * signed-in ProviderUser and the portal context into their services. Writes
 * already go through Providers\Contracts\ProviderRegistry, and portal pages that
 * need only the provider's id use ProviderPortalSession. Next: services that take
 * a Provider for its id, then a provider read model (Modules/MIGRATION.md).
 */
return [
    'Providers' => [
        // Modules\Cafeteria
        'Modules\Cafeteria\Application\Actions\CreateCafeteriaProviderAction',
        'Modules\Cafeteria\Application\Actions\ProcessCafeteriaQrScanAction',
        'Modules\Cafeteria\Application\Actions\UpdateCafeteriaProviderAction',
        'Modules\Cafeteria\Application\Policies\CafeteriaTransactionPolicy',
        'Modules\Cafeteria\Application\Services\Network\CafeteriaAssignmentService',
        'Modules\Cafeteria\Application\Services\Network\CafeteriaProviderRegistry',
        'Modules\Cafeteria\Application\Services\Policy\CafeteriaPolicyResolution',
        'Modules\Cafeteria\Application\Services\ProviderTransactionExportService',
        'Modules\Cafeteria\Application\Services\Reports\CafeteriaAnalyticsReportService',
        'Modules\Cafeteria\Application\Services\Settlement\CafeteriaSettlementService',
        'Modules\Cafeteria\Domain\Models\CafeteriaProvider',
        'Modules\Cafeteria\Domain\Models\CafeteriaServiceAssignment',
        'Modules\Cafeteria\Domain\Models\CafeteriaServiceNetwork',
        'Modules\Cafeteria\Domain\Models\CafeteriaServicePolicy',
        'Modules\Cafeteria\Domain\Models\CafeteriaSettlement',
        'Modules\Cafeteria\Domain\Models\CafeteriaTransaction',
        'Modules\Cafeteria\Http\Controllers\CafeteriaPayeeController',
        'Modules\Cafeteria\Http\Controllers\CafeteriaProviderController',
        'Modules\Cafeteria\Http\Controllers\CafeteriaSettlementController',
        'Modules\Cafeteria\Http\Controllers\Concerns\CafeteriaAdminContext',
        'Modules\Cafeteria\Http\Controllers\ProviderPortal\ProviderProfileController',
        'Modules\Cafeteria\Http\Requests\ProviderPortal\ProviderScanRequest',
        'Modules\Cafeteria\Providers\CafeteriaServiceProvider',
        // Modules\Transport
        'Modules\Transport\Application\Services\Boarding\BoardingRequest',
        'Modules\Transport\Application\Services\Settlement\TransportInvoiceService',
        'Modules\Transport\Application\Services\Settlement\TransportSettlementService',
        'Modules\Transport\Application\Services\TransportDeviceService',
        'Modules\Transport\Application\Services\TransportEligibilityService',
        'Modules\Transport\Application\Services\TransportQrScanService',
        'Modules\Transport\Application\Services\TransportReportService',
        'Modules\Transport\Application\Services\TransportTripService',
        'Modules\Transport\Domain\Models\TransportContract',
        'Modules\Transport\Domain\Models\TransportDevice',
        'Modules\Transport\Domain\Models\TransportDriver',
        'Modules\Transport\Domain\Models\TransportEligibility',
        'Modules\Transport\Domain\Models\TransportException',
        'Modules\Transport\Domain\Models\TransportInvoice',
        'Modules\Transport\Domain\Models\TransportPass',
        'Modules\Transport\Domain\Models\TransportProviderProfile',
        'Modules\Transport\Domain\Models\TransportRoute',
        'Modules\Transport\Domain\Models\TransportSchedule',
        'Modules\Transport\Domain\Models\TransportServiceCalendarDay',
        'Modules\Transport\Domain\Models\TransportSettlement',
        'Modules\Transport\Domain\Models\TransportTransaction',
        'Modules\Transport\Domain\Models\TransportTrip',
        'Modules\Transport\Domain\Models\TransportVehicle',
        'Modules\Transport\Http\Controllers\ProviderPortal\TransportDeviceController',
        'Modules\Transport\Http\Controllers\ProviderPortal\TransportDriverController',
        'Modules\Transport\Http\Controllers\ProviderPortal\TransportInvoiceController',
        'Modules\Transport\Http\Controllers\ProviderPortal\TransportRouteController',
        'Modules\Transport\Http\Controllers\ProviderPortal\TransportScanController',
        'Modules\Transport\Http\Controllers\ProviderPortal\TransportSettlementController',
        'Modules\Transport\Http\Controllers\ProviderPortal\TransportTripController',
        'Modules\Transport\Http\Controllers\ProviderPortal\TransportVehicleController',
        'Modules\Transport\Http\Controllers\TransportContractController',
        'Modules\Transport\Http\Controllers\TransportDeviceController',
        'Modules\Transport\Http\Controllers\TransportDriverController',
        'Modules\Transport\Http\Controllers\TransportEligibilityController',
        'Modules\Transport\Http\Controllers\TransportExceptionController',
        'Modules\Transport\Http\Controllers\TransportInvoiceController',
        'Modules\Transport\Http\Controllers\TransportManualLookupController',
        'Modules\Transport\Http\Controllers\TransportPassController',
        'Modules\Transport\Http\Controllers\TransportProviderController',
        'Modules\Transport\Http\Controllers\TransportReportController',
        'Modules\Transport\Http\Controllers\TransportRouteController',
        'Modules\Transport\Http\Controllers\TransportScanController',
        'Modules\Transport\Http\Controllers\TransportScheduleController',
        'Modules\Transport\Http\Controllers\TransportSettlementController',
        'Modules\Transport\Http\Controllers\TransportTransactionController',
        'Modules\Transport\Http\Controllers\TransportTripController',
        'Modules\Transport\Http\Controllers\TransportVehicleController',
        'Modules\Transport\Http\Requests\Concerns\TransportProviderRequest',
        'Modules\Transport\Http\Requests\ProviderPortal\ProviderTransportInvoiceRequest',
        'Modules\Transport\Http\Requests\ProviderPortal\ProviderTransportScanRequest',
        'Modules\Transport\Http\Requests\StoreTransportContractRequest',
        'Modules\Transport\Http\Requests\StoreTransportPassRequest',
        'Modules\Transport\Providers\TransportServiceProvider',
    ],
];
