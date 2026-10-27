<?php

use App\Http\Controllers\ProviderPortal\Auth\ProviderLoginController;
use App\Http\Controllers\ProviderPortal\Auth\ProviderPasswordResetController;
use App\Http\Controllers\ProviderPortal\ProviderDashboardController;
use App\Http\Controllers\ProviderPortal\ProviderFoodOrderController;
use App\Http\Controllers\ProviderPortal\ProviderLedgerController;
use App\Http\Controllers\ProviderPortal\ProviderMenuController;
use App\Http\Controllers\ProviderPortal\ProviderProfileController;
use App\Http\Controllers\ProviderPortal\ProviderReportController;
use App\Http\Controllers\ProviderPortal\ProviderScanController;
use App\Http\Controllers\ProviderPortal\ProviderTransactionController;
use App\Http\Controllers\Web\Cafeteria\CafeteriaAccessController;
use App\Http\Controllers\Web\Cafeteria\CafeteriaAnalyticsController;
use App\Http\Controllers\Web\Cafeteria\CafeteriaNetworkController;
use App\Http\Controllers\Web\Cafeteria\CafeteriaPayeeController;
use App\Http\Controllers\Web\Cafeteria\CafeteriaServiceAssignmentController;
use App\Http\Controllers\Web\Cafeteria\CafeteriaServicePolicyController;
use App\Http\Controllers\Web\Cafeteria\CafeteriaSettlementController;
use App\Http\Controllers\Web\CafeteriaDashboardController;
use App\Http\Controllers\Web\CafeteriaDayRuleController;
use App\Http\Controllers\Web\CafeteriaProviderBranchController;
use App\Http\Controllers\Web\CafeteriaProviderController;
use App\Http\Controllers\Web\CafeteriaProviderDashboardController;
use App\Http\Controllers\Web\CafeteriaReportController;
use App\Http\Controllers\Web\CafeteriaSettingController;
use App\Http\Controllers\Web\CafeteriaSpecialDayController;
use App\Http\Controllers\Web\CafeteriaSubsidyLedgerController;
use App\Http\Controllers\Web\CafeteriaSubsidyRuleController;
use App\Http\Controllers\Web\CafeteriaTransactionController;
use App\Http\Controllers\Web\CoreController;
use App\Http\Controllers\Web\EmployeeCafeteriaExclusionController;
use App\Http\Controllers\Web\IntegrationCenterController;
use App\Http\Controllers\Web\MfaController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\PublicHolidayController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\ScanPhotoController;
use App\Http\Controllers\Web\SecurityCenterController;
use App\Http\Controllers\Web\SessionController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\SystemHealthController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

/*
 * The EUISIS cafeteria screens, served by the cafeteria platform. Route
 * names, paths and verbs are the EUISIS ones, so the copied pages
 * (cafeteria-platform/frontend) link and post exactly as they do in EUISIS.
 * Permission names are the EUISIS ones too, granted by the platform role
 * (App\Support\CorePermissions).
 */

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:20,1');
});

// Provider portal (the cafeteria operators' own sign-in, `provider` guard), as in EUISIS.
Route::middleware('guest:provider')->prefix('provider/portal')->name('provider.portal.')->group(function (): void {
    Route::get('/login', [ProviderLoginController::class, 'create'])->name('login');
    Route::post('/login', [ProviderLoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/forgot-password', [ProviderPasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ProviderPasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.send');
    Route::delete('/forgot-password', [ProviderPasswordResetController::class, 'cancel'])->name('password.cancel');
    Route::post('/reset-password', [ProviderPasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');
});
Route::get('/cafe', fn () => redirect()->route('provider.portal.login'))->name('provider.portal.shortcut');

Route::middleware(['auth:provider', 'provider.portal', 'provider.portal.context'])->prefix('provider/portal')->name('provider.portal.')->group(function (): void {
    Route::post('/logout', [ProviderLoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', ProviderDashboardController::class)->name('dashboard');
    Route::get('/scan', [ProviderScanController::class, 'index'])->name('scan');
    Route::post('/scan', [ProviderScanController::class, 'store'])->middleware('throttle:60,1')->name('scan.store');
    Route::get('/scan/today', [ProviderScanController::class, 'today'])->name('scan.today');
    Route::get('/scan/calendar', [ProviderScanController::class, 'calendar'])->name('scan.calendar');
    Route::get('/transactions', [ProviderTransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/export', [ProviderTransactionController::class, 'export'])->middleware('throttle:10,1')->name('transactions.export');
    Route::get('/transactions/export/csv', [ProviderTransactionController::class, 'exportCsv'])->middleware('throttle:10,1')->name('transactions.export.csv');
    Route::get('/transactions/export/xlsx', [ProviderTransactionController::class, 'exportXlsx'])->middleware('throttle:10,1')->name('transactions.export.xlsx');
    Route::get('/transactions/export/pdf', [ProviderTransactionController::class, 'exportPdf'])->middleware('throttle:10,1')->name('transactions.export.pdf');
    Route::get('/transactions/export/payment-claim', [ProviderTransactionController::class, 'exportPaymentClaim'])->middleware('throttle:10,1')->name('transactions.export.payment-claim');
    Route::get('/transactions/export/payment-claim/xlsx', [ProviderTransactionController::class, 'exportPaymentClaimXlsx'])->middleware('throttle:10,1')->name('transactions.export.payment-claim.xlsx');
    Route::get('/transactions/export/payment-claim/pdf', [ProviderTransactionController::class, 'exportPaymentClaimPdf'])->middleware('throttle:10,1')->name('transactions.export.payment-claim.pdf');
    Route::get('/transactions/{transaction}', [ProviderTransactionController::class, 'show'])->name('transactions.show');
    Route::get('/ledger', [ProviderLedgerController::class, 'index'])->name('ledger.index');
    Route::get('/menus', [ProviderMenuController::class, 'index'])->name('menus.index');
    Route::get('/menus/create', [ProviderMenuController::class, 'create'])->name('menus.create');
    Route::post('/menus', [ProviderMenuController::class, 'store'])->name('menus.store');
    Route::get('/menus/{menu}', [ProviderMenuController::class, 'show'])->name('menus.show');
    Route::get('/menus/{menu}/edit', [ProviderMenuController::class, 'edit'])->name('menus.edit');
    Route::match(['put', 'patch'], '/menus/{menu}', [ProviderMenuController::class, 'update'])->name('menus.update');
    Route::post('/menus/{menu}/publish', [ProviderMenuController::class, 'publish'])->name('menus.publish');
    Route::post('/menus/{menu}/close', [ProviderMenuController::class, 'close'])->name('menus.close');
    Route::delete('/menus/{menu}', [ProviderMenuController::class, 'destroy'])->name('menus.destroy');
    Route::get('/orders', [ProviderFoodOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [ProviderFoodOrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/confirm', [ProviderFoodOrderController::class, 'confirm'])->name('orders.confirm');
    Route::post('/orders/{order}/prepare', [ProviderFoodOrderController::class, 'prepare'])->name('orders.prepare');
    Route::post('/orders/{order}/serve', [ProviderFoodOrderController::class, 'serve'])->name('orders.serve');
    Route::post('/orders/{order}/reject', [ProviderFoodOrderController::class, 'reject'])->name('orders.reject');
    Route::post('/orders/{order}/cancel', [ProviderFoodOrderController::class, 'cancel'])->name('orders.cancel');
    Route::get('/reports', [ProviderReportController::class, 'index'])->name('reports.index');
    Route::get('/profile', [ProviderProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProviderProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/password', [ProviderProfileController::class, 'updatePassword'])->middleware('throttle:6,1')->name('profile.password');
    Route::middleware('provider.service:cafeteria')->prefix('cafeteria')->name('cafeteria.')->group(function (): void {
        Route::get('/', ProviderDashboardController::class)->name('dashboard');
        Route::get('/scan', [ProviderScanController::class, 'index'])->name('scan');
        Route::post('/scan', [ProviderScanController::class, 'store'])->middleware('throttle:60,1')->name('scan.store');
        Route::get('/scan/today', [ProviderScanController::class, 'today'])->name('scan.today');
        Route::get('/scan/calendar', [ProviderScanController::class, 'calendar'])->name('scan.calendar');
    });
});

// The holder's photo on a scan result: a short-lived signed link (App\Support\EmployeeScanPhoto).
Route::get('/scan-photos/{employee}', ScanPhotoController::class)->middleware(['signed', 'throttle:120,1'])->name('employees.scan-photo');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::get('/mfa/setup', [MfaController::class, 'showSetup'])->name('mfa.setup');
    Route::post('/mfa/setup/confirm', [MfaController::class, 'confirmSetup'])->middleware('throttle:6,1')->name('mfa.setup.confirm');
    Route::get('/mfa/challenge', [MfaController::class, 'showChallenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [MfaController::class, 'verifyChallenge'])->middleware('throttle:6,1')->name('mfa.challenge.verify');
    Route::post('/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:6,1')->name('mfa.disable');
    Route::get('/change-password', [SessionController::class, 'forcedPassword'])->name('password.forced');
    Route::post('/change-password', [SessionController::class, 'updateForcedPassword'])->middleware('throttle:6,1')->name('password.forced.update');
    Route::post('/session/activity', [SessionController::class, 'activity'])->name('session.activity');
    Route::get('/session/status', [SessionController::class, 'status'])->name('session.status');
    Route::get('/notifications/feed', [SessionController::class, 'notifications'])->name('notifications.feed');
    Route::post('/notifications/read-all', [SessionController::class, 'readNotifications'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [SessionController::class, 'readNotifications'])->whereUuid('notification')->name('notifications.read');

    Route::get('/profile', [SessionController::class, 'profile'])->name('profile.edit');
    Route::patch('/profile', [SessionController::class, 'updateProfile'])->name('profile.update');
    Route::put('/password', [SessionController::class, 'updatePassword'])->middleware('throttle:6,1')->name('password.update');
    Route::delete('/profile/devices', [SessionController::class, 'destroyOtherDevices'])->name('profile.devices.destroy-others');
    Route::delete('/profile/devices/{session}', [SessionController::class, 'destroyDevice'])->name('profile.devices.destroy');

    Route::get('/', fn () => redirect()->route('cafeteria.dashboard'));
    Route::get('/dashboard', fn () => redirect()->route('cafeteria.dashboard'))->name('dashboard');

    // The platform's own administration (the skeleton's pages), under the EUISIS route names where EUISIS has them.
    Route::prefix('core')->name('core.')->group(function (): void {
        Route::get('/', fn () => redirect()->route('cafeteria.dashboard'))->name('dashboard');
        Route::get('/system-health', [SystemHealthController::class, 'show'])->name('system-health');
        Route::post('/system-health/jobs/{uuid}/retry', [SystemHealthController::class, 'retry'])->whereUuid('uuid')->middleware('throttle:30,1')->name('system-health.jobs.retry');
        Route::post('/system-health/jobs/{uuid}/forget', [SystemHealthController::class, 'forget'])->whereUuid('uuid')->middleware('throttle:30,1')->name('system-health.jobs.forget');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('/notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::get('/settings', fn () => redirect()->route('system-settings.index', ['tab' => request()->query('tab', 'general')]))->name('settings');
        Route::post('/exit', [SessionController::class, 'exitCore'])->name('exit');
        Route::middleware('can:core.access')->prefix('security')->name('security')->group(function (): void {
            Route::get('/', [SecurityCenterController::class, 'show']);
            Route::post('/break-glass', [SecurityCenterController::class, 'store'])->middleware('throttle:10,1')->name('.break-glass.store');
            Route::post('/break-glass/{grant}/decide', [SecurityCenterController::class, 'decide'])->name('.break-glass.decide');
            Route::post('/break-glass/{grant}/end', [SecurityCenterController::class, 'end'])->name('.break-glass.end');
            Route::post('/audit/verify', [SecurityCenterController::class, 'verifyAudit'])->middleware('throttle:3,10')->name('.audit.verify');
        });
        Route::middleware('can:integrations.view')->prefix('integration')->name('integration')->group(function (): void {
            Route::get('/', [IntegrationCenterController::class, 'show']);
            Route::post('/clients', [IntegrationCenterController::class, 'storeClient'])->name('.clients.store');
            Route::put('/clients/{apiClient}', [IntegrationCenterController::class, 'updateClient'])->name('.clients.update');
            Route::post('/clients/{apiClient}/rotate', [IntegrationCenterController::class, 'rotateClient'])->middleware('throttle:10,1')->name('.clients.rotate');
            Route::post('/clients/{apiClient}/revoke', [IntegrationCenterController::class, 'revokeClient'])->name('.clients.revoke');
            Route::post('/webhooks', [IntegrationCenterController::class, 'storeWebhook'])->name('.webhooks.store');
            Route::put('/webhooks/{webhook}', [IntegrationCenterController::class, 'updateWebhook'])->name('.webhooks.update');
            Route::post('/webhooks/{webhook}/rotate-secret', [IntegrationCenterController::class, 'rotateWebhook'])->middleware('throttle:10,1')->name('.webhooks.rotate');
            Route::post('/webhooks/{webhook}/resume', [IntegrationCenterController::class, 'resumeWebhook'])->name('.webhooks.resume');
            Route::post('/deliveries/{delivery}/retry', [IntegrationCenterController::class, 'retryDelivery'])->middleware('throttle:30,1')->name('.deliveries.retry');
        });
    });
    Route::middleware('can:integrations.view')->group(function (): void {
        Route::get('/api-management', fn () => redirect()->route('core.integration', ['tab' => 'clients']))->name('api-management.index');
        Route::get('/integrations', fn () => redirect()->route('core.integration', ['tab' => 'webhooks']))->name('integrations.index');
    });

    // The EUISIS System Settings page over the platform settings (SettingsController::TABS).
    Route::middleware('can:system-settings.view')->group(function (): void {
        Route::get('/system-settings', [SettingsController::class, 'index'])->name('system-settings.index');
        foreach (['general' => 'general', 'localization' => 'localization', 'appearance' => 'appearance', 'security' => 'security', 'id-cards' => 'id_cards',
            'notifications' => 'notifications', 'email' => 'email', 'sms' => 'sms', 'telegram' => 'telegram', 'field-work-gps' => 'field_work_gps',
            'situation-room' => 'situation_room', 'intelligence' => 'intelligence', 'assistant' => 'assistant'] as $path => $section) {
            Route::patch("/system-settings/{$path}", [SettingsController::class, 'update'])->defaults('section', $section)
                ->middleware('can:system-settings.update')->name("system-settings.{$path}.update");
        }
        Route::post('/system-settings/test-email', [SettingsController::class, 'testEmail'])->middleware(['can:system-settings.update', 'throttle:5,1'])->name('system-settings.test-email');
        Route::post('/system-settings/test-sms', [SettingsController::class, 'testSms'])->middleware(['can:system-settings.update', 'throttle:5,1'])->name('system-settings.test-sms');
        Route::post('/system-settings/test-telegram', [SettingsController::class, 'testTelegram'])->middleware(['can:system-settings.update', 'throttle:5,1'])->name('system-settings.test-telegram');
        Route::post('/system-settings/clear-cache', [SettingsController::class, 'clearCache'])->middleware('can:system-settings.update')->name('system-settings.clear-cache');
    });

    Route::get('/audit-logs', [CoreController::class, 'auditLogs'])->middleware('can:audit.view')->name('audit-logs.index');

    // Accounts
    Route::middleware('can:users.viewAny')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->middleware('can:users.create')->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->middleware('can:users.create')->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware('can:users.update')->name('users.edit');
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('can:users.update')->name('users.update');
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('can:users.update')->name('users.deactivate');
        Route::post('/users/{user}/restore', [UserController::class, 'restore'])->middleware('can:users.restore')->name('users.restore');
        Route::post('/users/{user}/organization-scopes', [UserController::class, 'storeScope'])->middleware('can:users.update')->name('users.organization-scopes.store');
        Route::put('/users/{user}/organization-scopes/{scope}', [UserController::class, 'storeScope'])->middleware('can:users.update')->name('users.organization-scopes.update');
        Route::delete('/users/{user}/organization-scopes/{scope}', [UserController::class, 'destroyScope'])->middleware('can:users.update')->name('users.organization-scopes.destroy');
    });

    // Roles and their permissions (ADR 0009); changes are city-wide (ManageRoles checks roles.manage).
    Route::middleware('can:roles.viewAny')->group(function (): void {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->middleware('can:roles.manage')->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->middleware('can:roles.manage')->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->middleware('can:roles.manage')->name('roles.edit');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.manage')->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.manage')->name('roles.destroy');
    });

    // Cafeteria Subsidy Module — the EUISIS routes (routes/web.php there), unchanged.
    Route::prefix('cafeteria')->name('cafeteria.')->group(function (): void {
        Route::get('/', [CafeteriaDashboardController::class, 'index'])->name('dashboard');

        // QR Scan terminal
        Route::get('/scan', [CafeteriaTransactionController::class, 'scan'])->name('scan');
        Route::get('/scan/mobile', [CafeteriaTransactionController::class, 'scanMobile'])->name('scan.mobile');
        Route::get('/scan/calendar', [CafeteriaTransactionController::class, 'calendar'])->name('scan.calendar');
        Route::get('/scan/today', [CafeteriaTransactionController::class, 'today'])->name('scan.today');
        Route::post('/scan', [CafeteriaTransactionController::class, 'processScan'])->middleware('throttle:60,1')->name('scan.process');

        // Transactions
        Route::get('/transactions', [CafeteriaTransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/export/{format}', [CafeteriaTransactionController::class, 'exportStatement'])->whereIn('format', ['pdf', 'xlsx', 'print'])->middleware('throttle:10,1')->name('transactions.export');
        Route::get('/transactions/{cafeteriaTransaction}', [CafeteriaTransactionController::class, 'show'])->name('transactions.show');
        Route::post('/transactions/{cafeteriaTransaction}/reverse', [CafeteriaTransactionController::class, 'reverse'])->name('transactions.reverse');

        // Ledger
        Route::get('/ledger', [CafeteriaSubsidyLedgerController::class, 'index'])->name('ledger.index');

        // Reports
        Route::get('/reports', [CafeteriaReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [CafeteriaReportController::class, 'generate'])->name('reports.generate');
        Route::get('/reports/{cafeteriaReport}', [CafeteriaReportController::class, 'show'])->name('reports.show');

        // Provider Dashboard
        Route::get('/providers/dashboard', CafeteriaProviderDashboardController::class)->name('providers.dashboard');

        // Providers (cafeterias)
        Route::get('/providers', [CafeteriaProviderController::class, 'index'])->name('providers.index');
        Route::get('/providers/create', [CafeteriaProviderController::class, 'create'])->name('providers.create');
        Route::post('/providers', [CafeteriaProviderController::class, 'store'])->name('providers.store');
        Route::get('/providers/{cafeteriaProvider}', [CafeteriaProviderController::class, 'show'])->name('providers.show');
        Route::get('/providers/{cafeteriaProvider}/edit', [CafeteriaProviderController::class, 'edit'])->name('providers.edit');
        Route::patch('/providers/{cafeteriaProvider}', [CafeteriaProviderController::class, 'update'])->name('providers.update');
        Route::delete('/providers/{cafeteriaProvider}', [CafeteriaProviderController::class, 'archive'])->name('providers.archive');
        Route::post('/providers/{cafeteriaProvider}/restore', [CafeteriaProviderController::class, 'restore'])->name('providers.restore');

        // Provider Branches
        Route::get('/providers/{cafeteriaProvider}/branches/create', [CafeteriaProviderBranchController::class, 'create'])->name('providers.branches.create');
        Route::post('/providers/{cafeteriaProvider}/branches', [CafeteriaProviderBranchController::class, 'store'])->name('providers.branches.store');
        Route::get('/providers/{cafeteriaProvider}/branches/{branch}/edit', [CafeteriaProviderBranchController::class, 'edit'])->name('providers.branches.edit');
        Route::patch('/providers/{cafeteriaProvider}/branches/{branch}', [CafeteriaProviderBranchController::class, 'update'])->name('providers.branches.update');
        Route::delete('/providers/{cafeteriaProvider}/branches/{branch}', [CafeteriaProviderBranchController::class, 'archive'])->name('providers.branches.archive');

        // Subsidy Rules
        Route::get('/subsidy-rules', [CafeteriaSubsidyRuleController::class, 'index'])->name('subsidy-rules.index');
        Route::get('/subsidy-rules/create', [CafeteriaSubsidyRuleController::class, 'create'])->name('subsidy-rules.create');
        Route::post('/subsidy-rules', [CafeteriaSubsidyRuleController::class, 'store'])->name('subsidy-rules.store');
        Route::get('/subsidy-rules/{cafeteriaSubsidyRule}/edit', [CafeteriaSubsidyRuleController::class, 'edit'])->name('subsidy-rules.edit');
        Route::patch('/subsidy-rules/{cafeteriaSubsidyRule}', [CafeteriaSubsidyRuleController::class, 'update'])->name('subsidy-rules.update');
        Route::delete('/subsidy-rules/{cafeteriaSubsidyRule}', [CafeteriaSubsidyRuleController::class, 'archive'])->name('subsidy-rules.archive');

        // Public Holidays
        Route::get('/holidays', [PublicHolidayController::class, 'index'])->name('holidays.index');
        Route::get('/holidays/create', [PublicHolidayController::class, 'create'])->name('holidays.create');
        Route::post('/holidays', [PublicHolidayController::class, 'store'])->name('holidays.store');
        Route::get('/holidays/{publicHoliday}/edit', [PublicHolidayController::class, 'edit'])->name('holidays.edit');
        Route::patch('/holidays/{publicHoliday}', [PublicHolidayController::class, 'update'])->name('holidays.update');
        Route::delete('/holidays/{publicHoliday}', [PublicHolidayController::class, 'archive'])->name('holidays.archive');

        // Cafeteria Settings
        Route::get('/settings', [CafeteriaSettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [CafeteriaSettingController::class, 'update'])->name('settings.update');
        Route::post('/settings/provider-users', [CafeteriaSettingController::class, 'storeProviderUser'])->name('settings.provider-users.store');
        Route::patch('/settings/provider-users/{providerUser}', [CafeteriaSettingController::class, 'updateProviderUser'])->name('settings.provider-users.update');
        Route::delete('/settings/provider-users/{providerUser}', [CafeteriaSettingController::class, 'destroyProviderUser'])->name('settings.provider-users.destroy');

        // Day Rules
        Route::get('/day-rules', [CafeteriaDayRuleController::class, 'index'])->name('day-rules.index');
        Route::patch('/day-rules/{cafeteriaDayRule}', [CafeteriaDayRuleController::class, 'update'])->name('day-rules.update');

        // Special Days
        Route::get('/special-days', [CafeteriaSpecialDayController::class, 'index'])->name('special-days.index');
        Route::get('/special-days/create', [CafeteriaSpecialDayController::class, 'create'])->name('special-days.create');
        Route::post('/special-days', [CafeteriaSpecialDayController::class, 'store'])->name('special-days.store');
        Route::get('/special-days/{cafeteriaSpecialDay}/edit', [CafeteriaSpecialDayController::class, 'edit'])->name('special-days.edit');
        Route::patch('/special-days/{cafeteriaSpecialDay}', [CafeteriaSpecialDayController::class, 'update'])->name('special-days.update');
        Route::delete('/special-days/{cafeteriaSpecialDay}', [CafeteriaSpecialDayController::class, 'archive'])->name('special-days.archive');
        Route::post('/special-days/{cafeteriaSpecialDay}/restore', [CafeteriaSpecialDayController::class, 'restore'])->name('special-days.restore');

        // Employee exclusions
        Route::get('/employee-exclusions', [EmployeeCafeteriaExclusionController::class, 'index'])->name('employee-exclusions.index');
        Route::get('/employee-exclusions/create', [EmployeeCafeteriaExclusionController::class, 'create'])->name('employee-exclusions.create');
        Route::post('/employee-exclusions', [EmployeeCafeteriaExclusionController::class, 'store'])->name('employee-exclusions.store');
        Route::get('/employee-exclusions/{employeeCafeteriaExclusion}', [EmployeeCafeteriaExclusionController::class, 'show'])->name('employee-exclusions.show');
        Route::get('/employee-exclusions/{employeeCafeteriaExclusion}/edit', [EmployeeCafeteriaExclusionController::class, 'edit'])->name('employee-exclusions.edit');
        Route::patch('/employee-exclusions/{employeeCafeteriaExclusion}', [EmployeeCafeteriaExclusionController::class, 'update'])->name('employee-exclusions.update');
        Route::post('/employee-exclusions/{employeeCafeteriaExclusion}/end', [EmployeeCafeteriaExclusionController::class, 'end'])->name('employee-exclusions.end');
        Route::delete('/employee-exclusions/{employeeCafeteriaExclusion}', [EmployeeCafeteriaExclusionController::class, 'archive'])->name('employee-exclusions.archive');

        // ── Networks, access, assignments, policies, settlements (docs/cafeteria-policy-architecture.md) ──
        Route::middleware('can:cafeteria_providers.viewAny')->group(function (): void {
            Route::get('/payees', [CafeteriaPayeeController::class, 'index'])->name('payees.index');
        });
        Route::middleware('can:cafeteria_providers.create')->group(function (): void {
            Route::get('/payees/create', [CafeteriaPayeeController::class, 'create'])->name('payees.create');
            Route::post('/payees', [CafeteriaPayeeController::class, 'store'])->name('payees.store');
            Route::get('/payees/{payee}/edit', [CafeteriaPayeeController::class, 'edit'])->name('payees.edit');
            Route::patch('/payees/{payee}', [CafeteriaPayeeController::class, 'update'])->name('payees.update');
        });

        Route::middleware('can:cafeteria_networks.view')->group(function (): void {
            Route::get('/networks', [CafeteriaNetworkController::class, 'index'])->name('networks.index');
        });
        Route::middleware('can:cafeteria_networks.manage')->group(function (): void {
            Route::get('/networks/create', [CafeteriaNetworkController::class, 'create'])->name('networks.create');
            Route::post('/networks', [CafeteriaNetworkController::class, 'store'])->name('networks.store');
            Route::get('/networks/{network}/edit', [CafeteriaNetworkController::class, 'edit'])->name('networks.edit');
            Route::patch('/networks/{network}', [CafeteriaNetworkController::class, 'update'])->name('networks.update');
        });
        Route::get('/networks/{network}', [CafeteriaNetworkController::class, 'show'])->middleware('can:cafeteria_networks.view')->name('networks.show');

        Route::get('/organization-access', [CafeteriaAccessController::class, 'index'])->middleware('can:cafeteria_access.view')->name('access.index');
        Route::middleware('can:cafeteria_access.manage')->group(function (): void {
            Route::get('/organization-access/create', [CafeteriaAccessController::class, 'create'])->name('access.create');
            Route::post('/organization-access', [CafeteriaAccessController::class, 'store'])->name('access.store');
            Route::get('/organization-access/{access}/edit', [CafeteriaAccessController::class, 'edit'])->name('access.edit');
            Route::patch('/organization-access/{access}', [CafeteriaAccessController::class, 'update'])->name('access.update');
            Route::post('/organization-access/{access}/end', [CafeteriaAccessController::class, 'end'])->name('access.end');
        });
        Route::post('/organization-access/{access}/approve', [CafeteriaAccessController::class, 'approve'])->middleware('can:cafeteria_access.approve')->name('access.approve');

        Route::get('/service-assignments', [CafeteriaServiceAssignmentController::class, 'index'])->middleware('can:cafeteria_assignments.view')->name('assignments.index');
        Route::get('/service-assignments/create', [CafeteriaServiceAssignmentController::class, 'create'])->middleware('can:cafeteria_assignments.create')->name('assignments.create');
        Route::post('/service-assignments', [CafeteriaServiceAssignmentController::class, 'store'])->middleware('can:cafeteria_assignments.create')->name('assignments.store');
        Route::get('/service-assignments/{assignment}/edit', [CafeteriaServiceAssignmentController::class, 'edit'])->middleware('can:cafeteria_assignments.update')->name('assignments.edit');
        Route::patch('/service-assignments/{assignment}', [CafeteriaServiceAssignmentController::class, 'update'])->middleware('can:cafeteria_assignments.update')->name('assignments.update');
        Route::post('/service-assignments/{assignment}/approve', [CafeteriaServiceAssignmentController::class, 'approve'])->middleware('can:cafeteria_assignments.approve')->name('assignments.approve');
        Route::post('/service-assignments/{assignment}/end', [CafeteriaServiceAssignmentController::class, 'end'])->middleware('can:cafeteria_assignments.end')->name('assignments.end');

        Route::get('/service-policies', [CafeteriaServicePolicyController::class, 'index'])->middleware('can:cafeteria_policies.view')->name('policies.index');
        Route::get('/service-policies/create', [CafeteriaServicePolicyController::class, 'create'])->middleware('can:cafeteria_policies.create')->name('policies.create');
        Route::post('/service-policies', [CafeteriaServicePolicyController::class, 'store'])->middleware('can:cafeteria_policies.create')->name('policies.store');
        Route::get('/service-policies/{policy}', [CafeteriaServicePolicyController::class, 'show'])->middleware('can:cafeteria_policies.view')->name('policies.show');
        Route::get('/service-policies/{policy}/edit', [CafeteriaServicePolicyController::class, 'edit'])->middleware('can:cafeteria_policies.update_draft')->name('policies.edit');
        Route::patch('/service-policies/{policy}', [CafeteriaServicePolicyController::class, 'update'])->middleware('can:cafeteria_policies.update_draft')->name('policies.update');
        Route::post('/service-policies/{policy}/submit', [CafeteriaServicePolicyController::class, 'submit'])->middleware('can:cafeteria_policies.submit')->name('policies.submit');
        Route::post('/service-policies/{policy}/return', [CafeteriaServicePolicyController::class, 'returnToDraft'])->middleware('can:cafeteria_policies.review')->name('policies.return');
        Route::post('/service-policies/{policy}/approve', [CafeteriaServicePolicyController::class, 'approve'])->middleware('can:cafeteria_policies.approve')->name('policies.approve');
        Route::post('/service-policies/{policy}/activate', [CafeteriaServicePolicyController::class, 'activate'])->middleware('can:cafeteria_policies.activate')->name('policies.activate');
        Route::post('/service-policies/{policy}/end', [CafeteriaServicePolicyController::class, 'end'])->middleware('can:cafeteria_policies.end')->name('policies.end');
        Route::post('/service-policies/{policy}/cancel', [CafeteriaServicePolicyController::class, 'cancel'])->middleware('can:cafeteria_policies.end')->name('policies.cancel');
        Route::post('/service-policies/{policy}/new-version', [CafeteriaServicePolicyController::class, 'newVersion'])->middleware('can:cafeteria_policies.create')->name('policies.new-version');

        Route::get('/settlements', [CafeteriaSettlementController::class, 'index'])->middleware('can:cafeteria_settlements.view')->name('settlements.index');
        Route::middleware('can:cafeteria_settlements.manage')->group(function (): void {
            Route::get('/settlements/create', [CafeteriaSettlementController::class, 'create'])->name('settlements.create');
            Route::post('/settlements', [CafeteriaSettlementController::class, 'store'])->name('settlements.store');
            Route::post('/settlements/{settlement}/finalize', [CafeteriaSettlementController::class, 'finalize'])->name('settlements.finalize');
            Route::post('/settlements/{settlement}/cancel', [CafeteriaSettlementController::class, 'cancel'])->name('settlements.cancel');
        });
        Route::get('/settlements/{settlement}', [CafeteriaSettlementController::class, 'show'])->middleware('can:cafeteria_settlements.view')->name('settlements.show');
        Route::get('/settlements/{settlement}/export/{format}', [CafeteriaSettlementController::class, 'export'])->whereIn('format', ['xlsx', 'pdf'])->middleware(['can:cafeteria_settlements.view', 'throttle:10,1'])->name('settlements.export');

        Route::get('/analytics', [CafeteriaAnalyticsController::class, 'index'])->middleware('can:cafeteria_reports.view')->name('analytics.index');
        Route::get('/analytics/export', [CafeteriaAnalyticsController::class, 'export'])->middleware(['can:cafeteria_transactions.export', 'throttle:10,1'])->name('analytics.export');
    });
});
