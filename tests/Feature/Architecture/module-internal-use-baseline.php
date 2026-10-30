<?php

declare(strict_types=1);

/*
 * Classes outside a module that still use its internals, frozen when the module
 * moved (ADR 0017). ModulePublicApiTest ignores exactly these for that module and
 * fails when one no longer needs it, so the list can only shrink: replace a use
 * with the module's Contracts and delete the line.
 *
 * Providers (2026-10-30): other modules hold provider records in relations through
 * the published Providers\Contracts\ReadModels and write through ProviderRegistry;
 * pickers, checks, portal pages and actors use ProviderDirectory and
 * ProviderPortalSession. Left: the provider-guard export policy (the Gate hands it
 * the internal ProviderUser), the portal profile page (edits the user's own
 * account), and Transport's provider admin page and the transportProfile relation
 * its provider attaches to the internal Provider model.
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
        'Modules\Cafeteria\Application\Policies\CafeteriaTransactionPolicy',
        'Modules\Cafeteria\Http\Controllers\ProviderPortal\ProviderProfileController',
        // Modules\Transport
        'Modules\Transport\Http\Controllers\TransportProviderController',
        'Modules\Transport\Providers\TransportServiceProvider',
    ],
];
