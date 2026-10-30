<?php

declare(strict_types=1);

/*
 * Classes outside a module that still use its internals, frozen when the module
 * moved (ADR 0017). ModulePublicApiTest ignores exactly these for that module and
 * fails when one no longer needs it, so the list can only shrink: replace a use
 * with the module's Contracts and delete the line.
 *
 * Providers (2026-10-30): what is left is Transport and Cafeteria models that hold
 * provider records in Eloquent relations (provider(), submittedBy(), scannedBy()),
 * Cafeteria code that manages its payee providers, the provider-guard export
 * policy, and the portal profile page. Writes go through ProviderRegistry; pickers,
 * checks, portal pages and actors use ProviderDirectory and ProviderPortalSession.
 * Next: a provider read model or id-only relations (Modules/MIGRATION.md).
 */
return [
    'Entitlements' => [
        // Composition and owners that still read service-delivery records directly.
        'App\\Actions\\Transfers\\CompleteTransferAction',
        'App\\Actions\\Vacancy\\CompleteVacancyTransferAction',
        'App\\Actions\\Workflows\\GenerateSettlementReportAction',
        'App\\Http\\Controllers\\Api\\V1\\CardVerificationController',
        'App\\Http\\Controllers\\Api\\V1\\NfcController',
        'App\\Http\\Controllers\\Api\\V1\\OfflineSyncController',
        'App\\Http\\Controllers\\Employee\\EmployeePortalController',
        'App\\Http\\Controllers\\OrganizationDesign\\ServiceController',
        'App\\Http\\Controllers\\Web\\ApiManagementController',
        'App\\Http\\Controllers\\Web\\CodeRuleController',
        'App\\Http\\Controllers\\Web\\EntitlementController',
        'App\\Http\\Controllers\\Web\\NfcTerminalController',
        'App\\Http\\Requests\\OrganizationDesign\\ServiceRules',
        'App\\Http\\Requests\\StoreCodeRuleRequest',
        'App\\Http\\Requests\\UpdateCodeRuleRequest',
        'App\\Http\\Resources\\CodeRuleResource',
        'App\\Services\\CodeGeneration\\CodeFormatTokenResolver',
        'App\\Services\\CodeGeneration\\CodeRuleTargetRegistry',
        'App\\Services\\Dashboard\\DashboardMetricService',
        'App\\Services\\Integration\\IntegrationAccessService',
        'App\\Services\\OrganizationDesign\\Import\\DesignImportDefinitions',
        'App\\Services\\RecycleBin\\RecycleBinRegistry',
        'Modules\\Cafeteria\\Domain\\Models\\CafeteriaProvider',
        'Modules\\Cafeteria\\Domain\\Models\\CafeteriaProviderAssignment',
        'Modules\\Cafeteria\\Http\\Controllers\\CafeteriaSettingController',
        'Modules\\Cafeteria\\Providers\\CafeteriaServiceProvider',
        'Modules\\Providers\\Application\\Services\\ProviderUserAccountService',
        'Modules\\Providers\\Domain\\Models\\ProviderService',
        'Modules\\Providers\\Domain\\Models\\ProviderUserServicePermission',
        'Modules\\Providers\\Http\\Controllers\\ProviderUserController',
        'Modules\\Providers\\Http\\Middleware\\EnsureProviderServiceScope',
    ],
    'Providers' => [
        // Modules\Cafeteria
        'Modules\Cafeteria\Application\Actions\CreateCafeteriaProviderAction',
        'Modules\Cafeteria\Application\Actions\UpdateCafeteriaProviderAction',
        'Modules\Cafeteria\Application\Policies\CafeteriaTransactionPolicy',
        'Modules\Cafeteria\Application\Services\Network\CafeteriaAssignmentService',
        'Modules\Cafeteria\Application\Services\Network\CafeteriaProviderRegistry',
        'Modules\Cafeteria\Application\Services\Policy\CafeteriaPolicyResolution',
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
        'Modules\Cafeteria\Providers\CafeteriaServiceProvider',
        // Modules\Transport
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
        'Modules\Transport\Http\Controllers\TransportProviderController',
        'Modules\Transport\Providers\TransportServiceProvider',
    ],
];
