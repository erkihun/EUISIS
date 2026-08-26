<?php

declare(strict_types=1);

use App\Http\Controllers\Employee\EmployeePortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProviderPortal\Auth\ProviderLoginController;
use App\Http\Controllers\ProviderPortal\ProviderDashboardController;
use App\Http\Controllers\ProviderPortal\ProviderFoodOrderController;
use App\Http\Controllers\ProviderPortal\ProviderLedgerController;
use App\Http\Controllers\ProviderPortal\ProviderMenuController;
use App\Http\Controllers\ProviderPortal\ProviderProfileController;
use App\Http\Controllers\ProviderPortal\ProviderReportController;
use App\Http\Controllers\ProviderPortal\ProviderScanController;
use App\Http\Controllers\ProviderPortal\ProviderTransactionController;
use App\Http\Controllers\ProviderPortal\Transport\TransportDashboardController as ProviderTransportDashboardController;
use App\Http\Controllers\ProviderPortal\Transport\TransportDriverController as ProviderTransportDriverController;
use App\Http\Controllers\ProviderPortal\Transport\TransportReportController as ProviderTransportReportController;
use App\Http\Controllers\ProviderPortal\Transport\TransportRouteController as ProviderTransportRouteController;
use App\Http\Controllers\ProviderPortal\Transport\TransportScanController as ProviderTransportScanController;
use App\Http\Controllers\ProviderPortal\Transport\TransportTransactionController as ProviderTransportTransactionController;
use App\Http\Controllers\ProviderPortal\Transport\TransportTripController as ProviderTransportTripController;
use App\Http\Controllers\ProviderPortal\Transport\TransportVehicleController as ProviderTransportVehicleController;
use App\Http\Controllers\Public\PublicServicesController;
use App\Http\Controllers\Public\PublicSupportController;
use App\Http\Controllers\Public\PublicTransferAnnouncementController;
use App\Http\Controllers\Public\PublicVerifyController;
use App\Http\Controllers\Transfers\TransferAnnouncementController;
use App\Http\Controllers\Transfers\TransferApplicationController;
use App\Http\Controllers\Transfers\TransferDashboardController;
use App\Http\Controllers\Transfers\TransferSettingController;
use App\Http\Controllers\Transport\TransportDriverController;
use App\Http\Controllers\Transport\TransportPassController;
use App\Http\Controllers\Transport\TransportProviderController;
use App\Http\Controllers\Transport\TransportReportController;
use App\Http\Controllers\Transport\TransportRouteController;
use App\Http\Controllers\Transport\TransportScanController;
use App\Http\Controllers\Transport\TransportSettingsController;
use App\Http\Controllers\Transport\TransportVehicleController;
use App\Http\Controllers\Web\AdministrativeTribunalController;
use App\Http\Controllers\Web\ApiManagementController;
use App\Http\Controllers\Web\AuditLogController;
use App\Http\Controllers\Web\CafeteriaDashboardController;
use App\Http\Controllers\Web\CafeteriaDayRuleController;
use App\Http\Controllers\Web\CafeteriaProviderBranchController;
use App\Http\Controllers\Web\CafeteriaProviderController;
use App\Http\Controllers\Web\CafeteriaProviderDashboardController;
use App\Http\Controllers\Web\CafeteriaProviderUserController;
use App\Http\Controllers\Web\CafeteriaReportController;
use App\Http\Controllers\Web\CafeteriaSettingController;
use App\Http\Controllers\Web\CafeteriaSpecialDayController;
use App\Http\Controllers\Web\CafeteriaSubsidyLedgerController;
use App\Http\Controllers\Web\CafeteriaSubsidyRuleController;
use App\Http\Controllers\Web\CafeteriaTransactionController;
use App\Http\Controllers\Web\CardPrintBatchController;
use App\Http\Controllers\Web\CardRequestController;
use App\Http\Controllers\Web\CodeRuleController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EmployeeCafeteriaExclusionController;
use App\Http\Controllers\Web\EmployeeController;
use App\Http\Controllers\Web\EmployeeFeedbackQrController;
use App\Http\Controllers\Web\EntitlementController;
use App\Http\Controllers\Web\EntitlementRuleController;
use App\Http\Controllers\Web\GradeLevelController;
use App\Http\Controllers\Web\GrievanceCategoryController;
use App\Http\Controllers\Web\GrievanceCommitteeController;
use App\Http\Controllers\Web\GrievanceController;
use App\Http\Controllers\Web\GrievanceSlaRuleController;
use App\Http\Controllers\Web\HierarchyVersionController;
use App\Http\Controllers\Web\IdCardController;
use App\Http\Controllers\Web\IdCardExportController;
use App\Http\Controllers\Web\InstitutionOfficeController;
use App\Http\Controllers\Web\IsicActivityController;
use App\Http\Controllers\Web\OccupationController;
use App\Http\Controllers\Web\OrganizationController;
use App\Http\Controllers\Web\OrganizationEdgeController;
use App\Http\Controllers\Web\OrganizationStructureImportController;
use App\Http\Controllers\Web\OrganizationTypeController;
use App\Http\Controllers\Web\OrganizationUnitController;
use App\Http\Controllers\Web\OrganizationUnitRelationshipController;
use App\Http\Controllers\Web\OrganizationUnitTypeController;
use App\Http\Controllers\Web\PermissionController;
use App\Http\Controllers\Web\PositionController;
use App\Http\Controllers\Web\PositionEstablishmentController;
use App\Http\Controllers\Web\PositionServiceController;
use App\Http\Controllers\Web\PublicHolidayController;
use App\Http\Controllers\Web\PublicIdCheckerController;
use App\Http\Controllers\Web\PublicServiceFeedbackController;
use App\Http\Controllers\Web\RecycleBinController;
use App\Http\Controllers\Web\ReportingLineController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\ServiceFeedbackController;
use App\Http\Controllers\Web\ServiceProviderController;
use App\Http\Controllers\Web\ServiceProviderUserController;
use App\Http\Controllers\Web\ServiceTypeController;
use App\Http\Controllers\Web\SystemSettingController;
use App\Http\Controllers\Web\UserController;
use App\Http\Controllers\Web\UserOrganizationScopeController;
use App\Http\Controllers\Web\VacancyAnnouncementController;
use App\Http\Controllers\Web\VacancyApplicationController;
use App\Models\IdCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// /cafe → cafeteria provider login shortcut
Route::get('/cafe', function () {
    return redirect()->route('provider.portal.login');
})->name('cafe.login');

// /employee → employee login shortcut
Route::get('/employee', function () {
    return redirect()->route('login');
})->middleware('guest')->name('employee.login.redirect');

// Cafeteria Provider Portal — login (guest only for cafeteria_provider guard)
Route::middleware('guest:provider')->prefix('provider/portal')->name('provider.portal.')->group(function (): void {
    Route::get('/login', [ProviderLoginController::class, 'create'])->name('login');
    Route::post('/login', [ProviderLoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

// Cafeteria Provider Portal — authenticated pages (cafeteria_provider guard)
Route::middleware(['auth:provider', 'provider.portal', 'provider.portal.context'])
    ->prefix('provider/portal')
    ->name('provider.portal.')
    ->group(function (): void {
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
        Route::middleware('provider.service:cafeteria')->prefix('cafeteria')->name('cafeteria.')->group(function (): void {
            Route::get('/', ProviderDashboardController::class)->name('dashboard');
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
        });
        Route::middleware('provider.service:transport')->prefix('transport')->name('transport.')->group(function (): void {
            Route::get('/', ProviderTransportDashboardController::class)->name('index');
            Route::get('/dashboard', ProviderTransportDashboardController::class)->name('dashboard');
            Route::get('/scan', [ProviderTransportScanController::class, 'index'])->name('scan');
            Route::post('/scan', [ProviderTransportScanController::class, 'store'])->middleware('throttle:60,1')->name('scan.store');
            Route::get('/transactions', [ProviderTransportTransactionController::class, 'index'])->name('transactions.index');
            Route::get('/transactions/export/csv', [ProviderTransportTransactionController::class, 'exportCsv'])->middleware('throttle:10,1')->name('transactions.export.csv');
            Route::get('/transactions/{transaction}', [ProviderTransportTransactionController::class, 'show'])->name('transactions.show');
            Route::get('/routes', [ProviderTransportRouteController::class, 'index'])->name('routes.index');
            Route::get('/routes/create', [ProviderTransportRouteController::class, 'create'])->name('routes.create');
            Route::post('/routes', [ProviderTransportRouteController::class, 'store'])->name('routes.store');
            Route::get('/routes/{route}/edit', [ProviderTransportRouteController::class, 'edit'])->name('routes.edit');
            Route::patch('/routes/{route}', [ProviderTransportRouteController::class, 'update'])->name('routes.update');
            Route::get('/vehicles', [ProviderTransportVehicleController::class, 'index'])->name('vehicles.index');
            Route::get('/vehicles/create', [ProviderTransportVehicleController::class, 'create'])->name('vehicles.create');
            Route::post('/vehicles', [ProviderTransportVehicleController::class, 'store'])->name('vehicles.store');
            Route::get('/vehicles/{vehicle}/edit', [ProviderTransportVehicleController::class, 'edit'])->name('vehicles.edit');
            Route::patch('/vehicles/{vehicle}', [ProviderTransportVehicleController::class, 'update'])->name('vehicles.update');
            Route::get('/drivers', [ProviderTransportDriverController::class, 'index'])->name('drivers.index');
            Route::get('/drivers/create', [ProviderTransportDriverController::class, 'create'])->name('drivers.create');
            Route::post('/drivers', [ProviderTransportDriverController::class, 'store'])->name('drivers.store');
            Route::get('/drivers/{driver}/edit', [ProviderTransportDriverController::class, 'edit'])->name('drivers.edit');
            Route::patch('/drivers/{driver}', [ProviderTransportDriverController::class, 'update'])->name('drivers.update');
            Route::get('/trips', [ProviderTransportTripController::class, 'index'])->name('trips.index');
            Route::get('/trips/create', [ProviderTransportTripController::class, 'create'])->name('trips.create');
            Route::post('/trips', [ProviderTransportTripController::class, 'store'])->name('trips.store');
            Route::get('/trips/{trip}/edit', [ProviderTransportTripController::class, 'edit'])->name('trips.edit');
            Route::patch('/trips/{trip}', [ProviderTransportTripController::class, 'update'])->name('trips.update');
            Route::get('/reports', [ProviderTransportReportController::class, 'index'])->name('reports.index');
        });
        Route::get('/profile', [ProviderProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [ProviderProfileController::class, 'update'])->name('profile.update');
        Route::patch('/profile/password', [ProviderProfileController::class, 'updatePassword'])->name('profile.password');
    });

// Legacy cafeteria/portal aliases — all redirect to the general provider portal.
// Login routes are guest-only to prevent CSRF-forgery against authenticated sessions.
Route::prefix('cafeteria/portal')->name('cafeteria.portal.')->group(function (): void {
    Route::middleware('guest:provider')->group(function (): void {
        Route::get('/login', fn () => redirect()->route('provider.portal.login'))->name('login');
        Route::post('/login', fn () => redirect()->route('provider.portal.login'))->name('login.store');
    });
    Route::post('/logout', fn () => redirect()->route('provider.portal.logout'))->name('logout');
    Route::get('/dashboard', fn () => redirect()->route('provider.portal.dashboard'))->name('dashboard');
    Route::get('/scan', fn () => redirect()->route('provider.portal.scan'))->name('scan');
    Route::get('/transactions', fn () => redirect()->route('provider.portal.transactions.index'))->name('transactions.index');
    Route::get('/ledger', fn () => redirect()->route('provider.portal.ledger.index'))->name('ledger.index');
    Route::get('/menus', fn () => redirect()->route('provider.portal.menus.index'))->name('menus.index');
    Route::get('/orders', fn () => redirect()->route('provider.portal.orders.index'))->name('orders.index');
    Route::get('/reports', fn () => redirect()->route('provider.portal.reports.index'))->name('reports.index');
    Route::get('/profile', fn () => redirect()->route('provider.portal.profile.show'))->name('profile.show');
});

// ── Public pages — no auth required ─────────────────────────────────────────
Route::get('/announcements', [PublicTransferAnnouncementController::class, 'index'])
    ->name('public.transfer-announcements');

Route::get('/announcements/transfer/{announcement}', [PublicTransferAnnouncementController::class, 'show'])
    ->name('public.transfer-announcements.show');

// Employee portal + apply routes — auth required, no MFA/verification gate
Route::middleware(['auth', 'admin.access'])->group(function (): void {
    Route::get('/my-portal', [EmployeePortalController::class, 'index'])
        ->name('employee.portal');

    Route::get('/my-portal/entitlements', [EmployeePortalController::class, 'myEntitlements'])
        ->name('employee.entitlements');

    Route::get('/my-portal/transfer-applications', [EmployeePortalController::class, 'myTransferApplications'])
        ->name('employee.transfer-applications');

    Route::get('/announcements/transfer/{announcement}/apply', [PublicTransferAnnouncementController::class, 'apply'])
        ->name('public.transfer-announcements.apply');

    Route::post('/announcements/transfer/{announcement}/apply', [PublicTransferAnnouncementController::class, 'storeApply'])
        ->name('public.transfer-announcements.apply.store');
});

Route::get('/verify', [PublicVerifyController::class, 'index'])
    ->name('public.verify');

Route::get('/services', [PublicServicesController::class, 'index'])
    ->name('public.services');

Route::get('/support', [PublicSupportController::class, 'index'])
    ->name('public.support');

/*
 * Public Global ID Checker — anonymous, OTP-gated.
 *
 * The QR printed on a card opens /id-checker/{card_uuid}. That page reveals
 * only whether the card is active; the holder's details require a one-time
 * code sent to the holder's own email and phone.
 *
 * Rate limits: browsing is generous, issuing a code is tight (it sends real
 * mail/SMS to an employee), and verification is throttled on top of the
 * per-code attempt ceiling enforced in the service.
 */
Route::get('/id-checker', [PublicIdCheckerController::class, 'index'])
    ->middleware('throttle:30,1')
    ->name('id-checker.index');

Route::get('/id-checker/{cardUuid}', [PublicIdCheckerController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('id-checker.show');

Route::post('/id-checker/{cardUuid}/send-otp', [PublicIdCheckerController::class, 'sendOtp'])
    ->middleware('throttle:id-checker-send-otp')
    ->name('id-checker.send-otp');

Route::post('/id-checker/{cardUuid}/verify-otp', [PublicIdCheckerController::class, 'verifyOtp'])
    ->middleware('throttle:id-checker-verify-otp')
    ->name('id-checker.verify-otp');

/*
 * Public Client Service Feedback — anonymous, no login.
 *
 * A separate QR from the ID card. Scanning opens a rating form for the service
 * an employee provided; the page shows the office, unit and role but never the
 * employee's name or any contact detail, so a leaked QR cannot be used to look
 * a person up.
 *
 * Rate limits: viewing is generous enough for a busy service desk, submitting
 * is tight and keyed on both token and IP so neither one client nor one desk
 * can be flooded with fabricated ratings.
 */
Route::get('/service-feedback/{token}', [PublicServiceFeedbackController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('service-feedback.show');

Route::post('/service-feedback/{token}', [PublicServiceFeedbackController::class, 'store'])
    ->middleware('throttle:service-feedback-submit')
    ->name('service-feedback.store');

/*
 * Legacy public card verification.
 *
 * This page displayed the organization and card number to anyone who scanned,
 * with no consent from the card holder. It is kept only as a redirect because
 * cards printed before the Global ID Checker carry this URL in their QR — a
 * physical card cannot be patched, so the link has to keep working.
 *
 * Everything now lands on the OTP-gated checker instead.
 */
Route::get('/verify/card/{publicCardUuid}', fn (string $publicCardUuid) => redirect()
    ->route('id-checker.show', $publicCardUuid, 301))
    ->name('id-cards.verify.public')
    ->middleware('throttle:30,1');

Route::middleware(['auth', 'verified', 'mfa', 'admin.access'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Organizations
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::get('/organizations/parent-options', [OrganizationController::class, 'parentOptions'])->name('organizations.parent-options');

    // Organization Structure Import wizard — declared before the
    // /organizations/{organization} wildcard so "import-structure" is not
    // captured as an organization id.
    Route::get('/organizations/import-structure', [OrganizationStructureImportController::class, 'create'])->name('organizations.import-structure.create');
    Route::get('/organizations/import-structure/template', [OrganizationStructureImportController::class, 'template'])->name('organizations.import-structure.template');
    Route::post('/organizations/import-structure/preview', [OrganizationStructureImportController::class, 'preview'])->name('organizations.import-structure.preview');
    Route::post('/organizations/import-structure/confirm', [OrganizationStructureImportController::class, 'confirm'])->name('organizations.import-structure.confirm');

    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::get('/organizations/{organization}/organogram', [OrganizationController::class, 'organogram'])->name('organizations.organogram');
    Route::get('/organizations/{organization}/edit', [OrganizationController::class, 'edit'])->name('organizations.edit');
    Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::delete('/organizations/{organization}', [OrganizationController::class, 'archive'])->name('organizations.archive');
    Route::patch('/organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate'])->name('organizations.deactivate');
    Route::delete('/organizations/{organization}/destroy', [OrganizationController::class, 'destroy'])->name('organizations.destroy');

    // Organization Types
    Route::get('/organization-types', [OrganizationTypeController::class, 'index'])->name('organization-types.index');
    Route::get('/organization-types/create', [OrganizationTypeController::class, 'create'])->name('organization-types.create');
    Route::post('/organization-types', [OrganizationTypeController::class, 'store'])->name('organization-types.store');
    Route::get('/organization-types/{organizationType}', [OrganizationTypeController::class, 'show'])->name('organization-types.show');
    Route::get('/organization-types/{organizationType}/edit', [OrganizationTypeController::class, 'edit'])->name('organization-types.edit');
    Route::patch('/organization-types/{organizationType}', [OrganizationTypeController::class, 'update'])->name('organization-types.update');
    Route::delete('/organization-types/{organizationType}', [OrganizationTypeController::class, 'destroy'])->name('organization-types.destroy');
    Route::post('/organization-types/{organizationType}/restore', [OrganizationTypeController::class, 'restore'])->name('organization-types.restore');

    // Hierarchy Versions
    Route::get('/hierarchy-versions', [HierarchyVersionController::class, 'index'])->name('hierarchy-versions.index');
    Route::get('/hierarchy-versions/create', [HierarchyVersionController::class, 'create'])->name('hierarchy-versions.create');
    Route::post('/hierarchy-versions', [HierarchyVersionController::class, 'store'])->name('hierarchy-versions.store');
    Route::get('/hierarchy-versions/{hierarchyVersion}', [HierarchyVersionController::class, 'show'])->name('hierarchy-versions.show');
    Route::get('/hierarchy-versions/{hierarchyVersion}/edit', [HierarchyVersionController::class, 'edit'])->name('hierarchy-versions.edit');
    Route::match(['put', 'patch'], '/hierarchy-versions/{hierarchyVersion}', [HierarchyVersionController::class, 'update'])->name('hierarchy-versions.update');
    Route::post('/hierarchy-versions/{hierarchyVersion}/publish', [HierarchyVersionController::class, 'publish'])->name('hierarchy-versions.publish');
    Route::post('/hierarchy-versions/{hierarchyVersion}/archive', [HierarchyVersionController::class, 'archive'])->name('hierarchy-versions.archive');
    Route::get('/hierarchy-versions/{hierarchyVersion}/tree', [HierarchyVersionController::class, 'tree'])->name('hierarchy-versions.tree');
    Route::get('/hierarchy-versions/{hierarchyVersion}/tree/edit', [HierarchyVersionController::class, 'editTree'])->name('hierarchy-versions.tree.edit');
    Route::get('/hierarchy-versions/{hierarchyVersion}/organization-options', [HierarchyVersionController::class, 'organizationOptions'])->name('hierarchy-versions.organization-options');
    Route::post('/hierarchy-versions/{hierarchyVersion}/edges', [OrganizationEdgeController::class, 'store'])->name('hierarchy-versions.edges.store');
    Route::patch('/hierarchy-versions/{hierarchyVersion}/edges/{organizationEdge}', [OrganizationEdgeController::class, 'update'])->name('hierarchy-versions.edges.update');
    Route::delete('/hierarchy-versions/{hierarchyVersion}/edges/{organizationEdge}', [OrganizationEdgeController::class, 'destroy'])->name('hierarchy-versions.edges.destroy');

    // Organization Units
    Route::get('/organization-units', [OrganizationUnitController::class, 'index'])->name('organization-units.index');
    Route::get('/organization-units/create', [OrganizationUnitController::class, 'create'])->name('organization-units.create');
    Route::post('/organization-units', [OrganizationUnitController::class, 'store'])->name('organization-units.store');
    Route::get('/organization-units/{organizationUnit}', [OrganizationUnitController::class, 'show'])->name('organization-units.show');
    Route::get('/organization-units/{organizationUnit}/relationships', [OrganizationUnitRelationshipController::class, 'index'])->name('organization-units.relationships.index');
    Route::post('/organization-units/{organizationUnit}/relationships', [OrganizationUnitRelationshipController::class, 'store'])->name('organization-units.relationships.store');
    Route::get('/organization-units/{organizationUnit}/relationships/{relationship}', [OrganizationUnitRelationshipController::class, 'show'])->name('organization-units.relationships.show');
    Route::match(['put', 'patch'], '/organization-units/{organizationUnit}/relationships/{relationship}', [OrganizationUnitRelationshipController::class, 'update'])->name('organization-units.relationships.update');
    Route::delete('/organization-units/{organizationUnit}/relationships/{relationship}', [OrganizationUnitRelationshipController::class, 'destroy'])->name('organization-units.relationships.destroy');
    Route::post('/organization-units/{organizationUnit}/relationships/{relationship}/restore', [OrganizationUnitRelationshipController::class, 'restore'])->name('organization-units.relationships.restore');
    Route::get('/organization-units/{organizationUnit}/edit', [OrganizationUnitController::class, 'edit'])->name('organization-units.edit');
    Route::patch('/organization-units/{organizationUnit}', [OrganizationUnitController::class, 'update'])->name('organization-units.update');
    Route::post('/organization-units/{organizationUnit}/archive', [OrganizationUnitController::class, 'archive'])->name('organization-units.archive');
    Route::post('/organization-units/{organizationUnit}/restore', [OrganizationUnitController::class, 'restore'])->name('organization-units.restore');
    Route::post('/organization-units/copy-structure', [OrganizationUnitController::class, 'copyStructure'])->name('organization-units.copy-structure');
    Route::get('/organizations/{organization}/units/options', [OrganizationUnitController::class, 'options'])->name('organizations.units.options');
    Route::get('/organizations/{organization}/units/tree', [OrganizationUnitController::class, 'tree'])->name('organizations.units.tree');

    // Organization Unit Types
    Route::get('/organization-unit-types', [OrganizationUnitTypeController::class, 'index'])->name('organization-unit-types.index');
    Route::get('/organization-unit-types/create', [OrganizationUnitTypeController::class, 'create'])->name('organization-unit-types.create');
    Route::post('/organization-unit-types', [OrganizationUnitTypeController::class, 'store'])->name('organization-unit-types.store');
    Route::get('/organization-unit-types/{organizationUnitType}', [OrganizationUnitTypeController::class, 'show'])->name('organization-unit-types.show');
    Route::get('/organization-unit-types/{organizationUnitType}/edit', [OrganizationUnitTypeController::class, 'edit'])->name('organization-unit-types.edit');
    Route::patch('/organization-unit-types/{organizationUnitType}', [OrganizationUnitTypeController::class, 'update'])->name('organization-unit-types.update');
    Route::post('/organization-unit-types/{organizationUnitType}/archive', [OrganizationUnitTypeController::class, 'archive'])->name('organization-unit-types.archive');
    Route::post('/organization-unit-types/{organizationUnitType}/restore', [OrganizationUnitTypeController::class, 'restore'])->name('organization-unit-types.restore');
    Route::get('/api/organization-unit-types/options', [OrganizationUnitTypeController::class, 'options'])->name('organization-unit-types.options');

    // Employees
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{employee}/transfers', [EmployeeController::class, 'transfer'])->name('employees.transfer');

    // Occupations
    Route::get('/occupations', [OccupationController::class, 'index'])->name('occupations.index');
    Route::get('/occupations/create', [OccupationController::class, 'create'])->name('occupations.create');
    Route::post('/occupations', [OccupationController::class, 'store'])->name('occupations.store');
    Route::get('/occupations/{occupation}', [OccupationController::class, 'show'])->name('occupations.show');
    Route::get('/occupations/{occupation}/edit', [OccupationController::class, 'edit'])->name('occupations.edit');
    Route::patch('/occupations/{occupation}', [OccupationController::class, 'update'])->name('occupations.update');
    Route::delete('/occupations/{occupation}', [OccupationController::class, 'archive'])->name('occupations.archive');
    Route::post('/occupations/{occupation}/restore', [OccupationController::class, 'restore'])->name('occupations.restore');

    // ISIC Activities
    Route::get('/isic-activities', [IsicActivityController::class, 'index'])->name('isic-activities.index');
    Route::get('/isic-activities/create', [IsicActivityController::class, 'create'])->name('isic-activities.create');
    Route::post('/isic-activities', [IsicActivityController::class, 'store'])->name('isic-activities.store');
    Route::get('/isic-activities/{isicActivity}', [IsicActivityController::class, 'show'])->name('isic-activities.show');
    Route::get('/isic-activities/{isicActivity}/edit', [IsicActivityController::class, 'edit'])->name('isic-activities.edit');
    Route::patch('/isic-activities/{isicActivity}', [IsicActivityController::class, 'update'])->name('isic-activities.update');
    Route::delete('/isic-activities/{isicActivity}', [IsicActivityController::class, 'archive'])->name('isic-activities.archive');
    Route::post('/isic-activities/{isicActivity}/restore', [IsicActivityController::class, 'restore'])->name('isic-activities.restore');

    // Positions
    Route::get('/positions', [PositionController::class, 'index'])->name('positions.index');
    Route::get('/positions/status', [PositionController::class, 'status'])->name('positions.status');
    Route::get('/positions/create', [PositionController::class, 'create'])->name('positions.create');
    Route::post('/positions', [PositionController::class, 'store'])->name('positions.store');
    Route::get('/positions/{position}', [PositionController::class, 'show'])->name('positions.show');
    Route::get('/positions/{position}/edit', [PositionController::class, 'edit'])->name('positions.edit');
    Route::patch('/positions/{position}', [PositionController::class, 'update'])->name('positions.update');
    Route::delete('/positions/{position}', [PositionController::class, 'archive'])->name('positions.archive');
    Route::post('/positions/{position}/restore', [PositionController::class, 'restore'])->name('positions.restore');
    Route::post('/positions/{position}/approve-establishment', [PositionController::class, 'approveEstablishment'])->name('positions.approve-establishment');

    // Grade Levels
    Route::get('/grade-levels', [GradeLevelController::class, 'index'])->name('grade-levels.index');
    Route::get('/grade-levels/create', [GradeLevelController::class, 'create'])->name('grade-levels.create');
    Route::post('/grade-levels', [GradeLevelController::class, 'store'])->name('grade-levels.store');
    Route::get('/grade-levels/{gradeLevel}', [GradeLevelController::class, 'show'])->name('grade-levels.show');
    Route::get('/grade-levels/{gradeLevel}/edit', [GradeLevelController::class, 'edit'])->name('grade-levels.edit');
    Route::patch('/grade-levels/{gradeLevel}', [GradeLevelController::class, 'update'])->name('grade-levels.update');
    Route::delete('/grade-levels/{gradeLevel}', [GradeLevelController::class, 'archive'])->name('grade-levels.archive');
    Route::post('/grade-levels/{gradeLevel}/restore', [GradeLevelController::class, 'restore'])->name('grade-levels.restore');

    // ── Transfer Module ────────────────────────────────────────────────────
    Route::get('/transfers', TransferDashboardController::class)->name('transfers.dashboard');
    Route::get('/transfers/settings', [TransferSettingController::class, 'show'])->name('transfer-settings.show');
    Route::patch('/transfers/settings', [TransferSettingController::class, 'update'])->name('transfer-settings.update');

    // Transfer Announcements
    Route::get('/transfer-announcements', [TransferAnnouncementController::class, 'index'])->name('transfer-announcements.index');
    Route::get('/transfer-announcements/create', [TransferAnnouncementController::class, 'create'])->name('transfer-announcements.create');
    Route::post('/transfer-announcements', [TransferAnnouncementController::class, 'store'])->name('transfer-announcements.store');
    Route::get('/transfer-announcements/{transferAnnouncement}', [TransferAnnouncementController::class, 'show'])->name('transfer-announcements.show');
    Route::get('/transfer-announcements/{transferAnnouncement}/edit', [TransferAnnouncementController::class, 'edit'])->name('transfer-announcements.edit');
    Route::patch('/transfer-announcements/{transferAnnouncement}', [TransferAnnouncementController::class, 'update'])->name('transfer-announcements.update');
    Route::post('/transfer-announcements/{transferAnnouncement}/publish', [TransferAnnouncementController::class, 'publish'])->name('transfer-announcements.publish');
    Route::post('/transfer-announcements/{transferAnnouncement}/close', [TransferAnnouncementController::class, 'close'])->name('transfer-announcements.close');
    Route::post('/transfer-announcements/{transferAnnouncement}/cancel', [TransferAnnouncementController::class, 'cancel'])->name('transfer-announcements.cancel');
    Route::delete('/transfer-announcements/{transferAnnouncement}', [TransferAnnouncementController::class, 'destroy'])->name('transfer-announcements.destroy');

    // Transfer Applications
    Route::get('/transfer-applications', [TransferApplicationController::class, 'index'])->name('transfer-applications.index');
    Route::post('/transfer-applications', [TransferApplicationController::class, 'store'])->name('transfer-applications.store');
    Route::get('/transfer-applications/{transferApplication}', [TransferApplicationController::class, 'show'])->name('transfer-applications.show');
    Route::post('/transfer-applications/{transferApplication}/screen', [TransferApplicationController::class, 'screen'])->name('transfer-applications.screen');
    Route::post('/transfer-applications/{transferApplication}/select', [TransferApplicationController::class, 'select'])->name('transfer-applications.select');
    Route::post('/transfer-applications/{transferApplication}/reject', [TransferApplicationController::class, 'reject'])->name('transfer-applications.reject');
    Route::post('/transfer-applications/{transferApplication}/withdraw', [TransferApplicationController::class, 'withdraw'])->name('transfer-applications.withdraw');
    Route::post('/transfer-applications/{transferApplication}/approve-release', [TransferApplicationController::class, 'approveRelease'])->name('transfer-applications.approve-release');
    Route::post('/transfer-applications/{transferApplication}/reject-release', [TransferApplicationController::class, 'rejectRelease'])->name('transfer-applications.reject-release');
    Route::post('/transfer-applications/{transferApplication}/approve-receiving', [TransferApplicationController::class, 'approveReceiving'])->name('transfer-applications.approve-receiving');
    Route::post('/transfer-applications/{transferApplication}/reject-receiving', [TransferApplicationController::class, 'rejectReceiving'])->name('transfer-applications.reject-receiving');
    Route::post('/transfer-applications/{transferApplication}/approve-final', [TransferApplicationController::class, 'approveFinal'])->name('transfer-applications.approve-final');
    Route::post('/transfer-applications/{transferApplication}/reject-final', [TransferApplicationController::class, 'rejectFinal'])->name('transfer-applications.reject-final');

    // Position Establishments
    Route::get('/position-establishments', [PositionEstablishmentController::class, 'index'])->name('position-establishments.index');
    Route::post('/position-establishments', [PositionEstablishmentController::class, 'store'])->name('position-establishments.store');
    Route::get('/position-establishments/{position_establishment}', [PositionEstablishmentController::class, 'show'])->name('position-establishments.show');
    Route::get('/position-establishments/{position_establishment}/edit', [PositionEstablishmentController::class, 'edit'])->name('position-establishments.edit');
    Route::patch('/position-establishments/{position_establishment}', [PositionEstablishmentController::class, 'update'])->name('position-establishments.update');
    Route::post('/position-establishments/{position_establishment}/approve', [PositionEstablishmentController::class, 'approve'])->name('position-establishments.approve');
    Route::delete('/position-establishments/{position_establishment}', [PositionEstablishmentController::class, 'archive'])->name('position-establishments.archive');

    // Vacancy Announcements
    Route::get('/vacancy-announcements', [VacancyAnnouncementController::class, 'index'])->name('vacancy-announcements.index');
    Route::get('/vacancy-announcements/create', [VacancyAnnouncementController::class, 'create'])->name('vacancy-announcements.create');
    Route::post('/vacancy-announcements', [VacancyAnnouncementController::class, 'store'])->name('vacancy-announcements.store');
    Route::get('/vacancy-announcements/{vacancy_announcement}', [VacancyAnnouncementController::class, 'show'])->name('vacancy-announcements.show');
    Route::get('/vacancy-announcements/{vacancy_announcement}/edit', [VacancyAnnouncementController::class, 'edit'])->name('vacancy-announcements.edit');
    Route::patch('/vacancy-announcements/{vacancy_announcement}', [VacancyAnnouncementController::class, 'update'])->name('vacancy-announcements.update');
    Route::post('/vacancy-announcements/{vacancy_announcement}/publish', [VacancyAnnouncementController::class, 'publish'])->name('vacancy-announcements.publish');
    Route::post('/vacancy-announcements/{vacancy_announcement}/close', [VacancyAnnouncementController::class, 'close'])->name('vacancy-announcements.close');
    Route::delete('/vacancy-announcements/{vacancy_announcement}', [VacancyAnnouncementController::class, 'destroy'])->name('vacancy-announcements.destroy');

    // Vacancy Applications
    Route::get('/vacancy-applications', [VacancyApplicationController::class, 'index'])->name('vacancy-applications.index');
    Route::get('/vacancy-applications/my', [VacancyApplicationController::class, 'myApplications'])->name('vacancy-applications.my-applications');
    Route::post('/vacancy-applications', [VacancyApplicationController::class, 'store'])->name('vacancy-applications.store');
    Route::get('/vacancy-applications/{vacancy_application}', [VacancyApplicationController::class, 'show'])->name('vacancy-applications.show');
    Route::post('/vacancy-applications/{vacancy_application}/withdraw', [VacancyApplicationController::class, 'withdraw'])->name('vacancy-applications.withdraw');
    Route::post('/vacancy-applications/{vacancy_application}/screen', [VacancyApplicationController::class, 'screen'])->name('vacancy-applications.screen');
    Route::post('/vacancy-applications/{vacancy_application}/shortlist', [VacancyApplicationController::class, 'shortlist'])->name('vacancy-applications.shortlist');
    Route::post('/vacancy-applications/{vacancy_application}/select', [VacancyApplicationController::class, 'select'])->name('vacancy-applications.select');
    Route::post('/vacancy-applications/{vacancy_application}/reject', [VacancyApplicationController::class, 'reject'])->name('vacancy-applications.reject');
    Route::post('/vacancy-applications/{vacancy_application}/initiate-transfer', [VacancyApplicationController::class, 'initiateTransfer'])->name('vacancy-applications.initiate-transfer');

    // ID Cards
    Route::get('/id-cards', [IdCardController::class, 'index'])->name('id-cards.index');
    Route::get('/id-cards/{card}', [IdCardController::class, 'show'])->name('id-cards.show');
    Route::get('/id-cards/{card}/preview', [IdCardController::class, 'preview'])->name('id-cards.preview');
    Route::post('/id-cards/{card}/issue', [IdCardController::class, 'issue'])->name('id-cards.issue');
    Route::post('/id-cards/{card}/activate', [IdCardController::class, 'activate'])->name('id-cards.activate');
    Route::post('/id-cards/{card}/report-lost', [IdCardController::class, 'reportLost'])->name('id-cards.report-lost');
    Route::post('/id-cards/{card}/report-damaged', [IdCardController::class, 'reportDamaged'])->name('id-cards.report-damaged');
    Route::post('/id-cards/{card}/replace', [IdCardController::class, 'replace'])->name('id-cards.replace');
    Route::post('/id-cards/{card}/revoke', [IdCardController::class, 'revoke'])->name('id-cards.revoke');
    Route::post('/id-cards/{card}/export/audit', [IdCardController::class, 'exportAudit'])->name('id-cards.export.audit');

    // Server-side SVG preview + PNG export (no status change, all actions audited)
    Route::get('/id-cards/{card}/preview/svg/front', [IdCardExportController::class, 'previewFrontSvg'])->name('id-cards.preview.svg.front');
    Route::get('/id-cards/{card}/preview/svg/back', [IdCardExportController::class, 'previewBackSvg'])->name('id-cards.preview.svg.back');
    Route::get('/id-cards/{card}/export/png/front', [IdCardExportController::class, 'exportFrontPng'])->name('id-cards.export.png.front');
    Route::get('/id-cards/{card}/export/png/back', [IdCardExportController::class, 'exportBackPng'])->name('id-cards.export.png.back');
    Route::get('/id-cards/{card}/export/png/both', [IdCardExportController::class, 'exportBothPng'])->name('id-cards.export.png.both');

    // Card Requests
    Route::get('/card-requests', [CardRequestController::class, 'index'])->name('card-requests.index');
    Route::get('/card-requests/create', [CardRequestController::class, 'create'])->name('card-requests.create');
    Route::post('/card-requests', [CardRequestController::class, 'store'])->name('card-requests.store');
    Route::get('/card-requests/{cardRequest}', [CardRequestController::class, 'show'])->name('card-requests.show');
    Route::post('/card-requests/{cardRequest}/verify', [CardRequestController::class, 'verify'])->name('card-requests.verify');
    Route::post('/card-requests/{cardRequest}/approve', [CardRequestController::class, 'approve'])->name('card-requests.approve');
    Route::post('/card-requests/{cardRequest}/reject', [CardRequestController::class, 'reject'])->name('card-requests.reject');
    Route::post('/card-requests/{cardRequest}/cancel', [CardRequestController::class, 'cancel'])->name('card-requests.cancel');

    // Print Batches
    Route::get('/print-batches', [CardPrintBatchController::class, 'index'])->name('print-batches.index');
    Route::get('/print-batches/create', [CardPrintBatchController::class, 'create'])->name('print-batches.create');
    Route::post('/print-batches', [CardPrintBatchController::class, 'store'])->name('print-batches.store');
    Route::get('/print-batches/{batch}', [CardPrintBatchController::class, 'show'])->name('print-batches.show');
    Route::post('/print-batches/{batch}/mark-printed', [CardPrintBatchController::class, 'markPrinted'])->name('print-batches.mark-printed');

    // Legacy ID Card routes (kept for backward compat with existing frontend)
    Route::post('/id-cards/requests', [CardRequestController::class, 'store'])->name('id-cards.requests.store');
    Route::post('/id-cards/requests/{cardRequest}/verify', [CardRequestController::class, 'verify'])->name('id-cards.requests.verify');
    Route::post('/id-cards/requests/{cardRequest}/approve', [CardRequestController::class, 'approve'])->name('id-cards.requests.approve');
    // Legacy incident route
    Route::post('/id-cards/{card}/incident', function (Request $request, IdCard $card) {
        $status = $request->input('status', 'lost');

        return redirect()->route($status === 'damaged' ? 'id-cards.report-damaged' : 'id-cards.report-lost', $card);
    })->name('id-cards.incident');

    // Service Providers & Entitlements
    Route::get('/service-types', [ServiceTypeController::class, 'index'])->name('service-types.index');
    Route::get('/service-types/create', [ServiceTypeController::class, 'create'])->name('service-types.create');
    Route::post('/service-types', [ServiceTypeController::class, 'store'])->name('service-types.store');
    Route::get('/service-types/{serviceType}', [ServiceTypeController::class, 'show'])->name('service-types.show');
    Route::get('/service-types/{serviceType}/edit', [ServiceTypeController::class, 'edit'])->name('service-types.edit');
    Route::patch('/service-types/{serviceType}', [ServiceTypeController::class, 'update'])->name('service-types.update');
    Route::delete('/service-types/{serviceType}', [ServiceTypeController::class, 'archive'])->name('service-types.archive');
    Route::post('/service-types/{serviceType}/restore', [ServiceTypeController::class, 'restore'])->name('service-types.restore');

    Route::get('/service-providers', [ServiceProviderController::class, 'index'])->name('service-providers.index');
    Route::get('/service-providers/create', [ServiceProviderController::class, 'create'])->name('service-providers.create');
    Route::post('/service-providers', [ServiceProviderController::class, 'store'])->name('service-providers.store');
    Route::get('/service-providers/{serviceProvider}', [ServiceProviderController::class, 'show'])->name('service-providers.show');
    Route::get('/service-providers/{serviceProvider}/edit', [ServiceProviderController::class, 'edit'])->name('service-providers.edit');
    Route::patch('/service-providers/{serviceProvider}', [ServiceProviderController::class, 'update'])->name('service-providers.update');
    Route::delete('/service-providers/{serviceProvider}', [ServiceProviderController::class, 'destroy'])->name('service-providers.destroy');
    Route::get('/entitlement-rules', [EntitlementRuleController::class, 'index'])->name('entitlement-rules.index');
    Route::get('/entitlement-rules/create', [EntitlementRuleController::class, 'create'])->name('entitlement-rules.create');
    Route::post('/entitlement-rules', [EntitlementRuleController::class, 'store'])->name('entitlement-rules.store');
    Route::get('/entitlement-rules/{entitlementRule}', [EntitlementRuleController::class, 'show'])->name('entitlement-rules.show');
    Route::get('/entitlement-rules/{entitlementRule}/edit', [EntitlementRuleController::class, 'edit'])->name('entitlement-rules.edit');
    Route::patch('/entitlement-rules/{entitlementRule}', [EntitlementRuleController::class, 'update'])->name('entitlement-rules.update');
    Route::delete('/entitlement-rules/{entitlementRule}', [EntitlementRuleController::class, 'archive'])->name('entitlement-rules.archive');
    Route::post('/entitlement-rules/{entitlementRule}/restore', [EntitlementRuleController::class, 'restore'])->name('entitlement-rules.restore');
    Route::get('/entitlements', [EntitlementController::class, 'index'])->name('entitlements.index');
    Route::post('/entitlements', [EntitlementController::class, 'store'])->name('entitlements.store');

    // Audit Logs
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    // Administration: Recycle Bin
    Route::get('/recycle-bin', [RecycleBinController::class, 'index'])->name('recycle-bin.index');
    Route::post('/recycle-bin/{type}/{id}/restore', [RecycleBinController::class, 'restore'])->name('recycle-bin.restore');
    Route::delete('/recycle-bin/{type}/{id}', [RecycleBinController::class, 'forceDelete'])->name('recycle-bin.force-delete');

    // Administration: Code Rules
    Route::get('/code-rules', [CodeRuleController::class, 'index'])->name('code-rules.index');
    Route::get('/code-rules/create', [CodeRuleController::class, 'create'])->name('code-rules.create');
    Route::post('/code-rules', [CodeRuleController::class, 'store'])->name('code-rules.store');
    Route::post('/code-rules/preview', [CodeRuleController::class, 'preview'])->name('code-rules.preview');
    Route::post('/code-rules/preview-code', [CodeRuleController::class, 'previewCode'])->name('code-rules.preview-code');
    Route::get('/code-rules/{codeRule}', [CodeRuleController::class, 'show'])->name('code-rules.show');
    Route::get('/code-rules/{codeRule}/edit', [CodeRuleController::class, 'edit'])->name('code-rules.edit');
    Route::match(['put', 'patch'], '/code-rules/{codeRule}', [CodeRuleController::class, 'update'])->name('code-rules.update');
    Route::post('/code-rules/{codeRule}/archive', [CodeRuleController::class, 'archive'])->name('code-rules.archive');
    Route::post('/code-rules/{codeRule}/restore', [CodeRuleController::class, 'restore'])->name('code-rules.restore');
    Route::get('/code-rules/{codeRule}/sequences', [CodeRuleController::class, 'sequences'])->name('code-rules.sequences');
    Route::post('/code-rules/{codeRule}/sequences/{sequence}/reset', [CodeRuleController::class, 'resetSequence'])->name('code-rules.sequences.reset');

    // Admin: Users
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/users/{user}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::post('/users/{user}/assign-roles', [UserController::class, 'assignRoles'])->name('users.assign-roles');

    Route::prefix('users/{user}')->group(function (): void {
        Route::get('/organization-scopes', [UserOrganizationScopeController::class, 'index'])->name('users.organization-scopes.index');
        Route::post('/organization-scopes', [UserOrganizationScopeController::class, 'store'])->name('users.organization-scopes.store');
        Route::put('/organization-scopes/{scope}', [UserOrganizationScopeController::class, 'update'])->name('users.organization-scopes.update');
        Route::delete('/organization-scopes/{scope}', [UserOrganizationScopeController::class, 'destroy'])->name('users.organization-scopes.destroy');
    });

    // Admin: Roles
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    // Admin: Permissions
    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
    Route::get('/permissions/create', [PermissionController::class, 'create'])->name('permissions.create');
    Route::post('/permissions', [PermissionController::class, 'store'])->name('permissions.store');
    Route::get('/permissions/{permission}', [PermissionController::class, 'show'])->name('permissions.show');
    Route::get('/permissions/{permission}/edit', [PermissionController::class, 'edit'])->name('permissions.edit');
    Route::patch('/permissions/{permission}', [PermissionController::class, 'update'])->name('permissions.update');
    Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy'])->name('permissions.destroy');

    // Admin: System Settings → API Management
    Route::get('/system-settings/api-management', [ApiManagementController::class, 'index'])->name('api-management.index');
    Route::post('/system-settings/api-management', [ApiManagementController::class, 'store'])->name('api-management.store');
    Route::get('/system-settings/api-management/logs', [ApiManagementController::class, 'logs'])->name('api-management.logs');
    Route::get('/system-settings/api-management/docs', [ApiManagementController::class, 'docs'])->name('api-management.docs');
    // Declared before the {externalApplication} route so "endpoints" is not
    // captured as an application id.
    Route::get('/system-settings/api-management/endpoints', [ApiManagementController::class, 'endpoints'])->name('api-management.endpoints');
    Route::post('/system-settings/api-management/endpoints/sync', [ApiManagementController::class, 'syncEndpoints'])->name('api-management.endpoints.sync');
    Route::get('/system-settings/api-management/endpoints/{apiEndpointDefinition}', [ApiManagementController::class, 'showEndpoint'])->name('api-management.endpoints.show');
    Route::patch('/system-settings/api-management/endpoints/{apiEndpointDefinition}', [ApiManagementController::class, 'updateEndpoint'])->name('api-management.endpoints.update');
    Route::get('/system-settings/api-management/{externalApplication}', [ApiManagementController::class, 'show'])->name('api-management.show');
    Route::patch('/system-settings/api-management/{externalApplication}', [ApiManagementController::class, 'update'])->name('api-management.update');
    Route::delete('/system-settings/api-management/{externalApplication}', [ApiManagementController::class, 'destroy'])->name('api-management.destroy');
    Route::post('/system-settings/api-management/{externalApplication}/tokens', [ApiManagementController::class, 'storeToken'])->name('api-management.tokens.store');
    Route::delete('/system-settings/api-management/{externalApplication}/tokens/{tokenId}', [ApiManagementController::class, 'destroyToken'])->name('api-management.tokens.destroy');

    // Admin: System Settings
    Route::get('/system-settings', [SystemSettingController::class, 'index'])->name('system-settings.index');
    Route::patch('/system-settings/general', [SystemSettingController::class, 'updateGeneral'])->name('system-settings.general.update');
    Route::patch('/system-settings/localization', [SystemSettingController::class, 'updateLocalization'])->name('system-settings.localization.update');
    Route::patch('/system-settings/notifications', [SystemSettingController::class, 'updateNotifications'])->name('system-settings.notifications.update');
    Route::patch('/system-settings/email', [SystemSettingController::class, 'updateEmail'])->name('system-settings.email.update');
    Route::patch('/system-settings/sms', [SystemSettingController::class, 'updateSms'])->name('system-settings.sms.update');
    Route::patch('/system-settings/telegram', [SystemSettingController::class, 'updateTelegram'])->name('system-settings.telegram.update');
    Route::patch('/system-settings/security', [SystemSettingController::class, 'updateSecurity'])->name('system-settings.security.update');
    Route::patch('/system-settings/appearance', [SystemSettingController::class, 'updateAppearance'])->name('system-settings.appearance.update');
    Route::patch('/system-settings/id-cards', [SystemSettingController::class, 'updateIdCards'])->name('system-settings.id-cards.update');
    Route::post('/system-settings/test-email', [SystemSettingController::class, 'testEmail'])->name('system-settings.test-email');
    Route::post('/system-settings/test-sms', [SystemSettingController::class, 'testSms'])->name('system-settings.test-sms');
    Route::post('/system-settings/test-telegram', [SystemSettingController::class, 'testTelegram'])->name('system-settings.test-telegram');
    Route::post('/system-settings/clear-cache', [SystemSettingController::class, 'clearCache'])->name('system-settings.clear-cache');
    Route::patch('/system-settings/{setting}', [SystemSettingController::class, 'update'])->name('system-settings.update');

    // Provider Users — standalone service provider credential accounts
    Route::get('/provider-users', ServiceProviderUserController::class)->name('provider-users.index');
    Route::get('/provider-users/create', [ServiceProviderUserController::class, 'create'])->name('provider-users.create');
    Route::post('/provider-users', [ServiceProviderUserController::class, 'store'])->name('provider-users.store');
    Route::get('/provider-users/{providerUser}', [ServiceProviderUserController::class, 'show'])->name('provider-users.show');
    Route::get('/provider-users/{providerUser}/edit', [ServiceProviderUserController::class, 'edit'])->name('provider-users.edit');
    Route::patch('/provider-users/{providerUser}', [ServiceProviderUserController::class, 'update'])->name('provider-users.update');
    Route::delete('/provider-users/{providerUser}', [ServiceProviderUserController::class, 'destroy'])->name('provider-users.destroy');
    Route::post('/provider-users/{providerUser}/suspend', [ServiceProviderUserController::class, 'suspend'])->name('provider-users.suspend');
    Route::post('/provider-users/{providerUser}/activate', [ServiceProviderUserController::class, 'activate'])->name('provider-users.activate');
    Route::post('/provider-users/{providerUser}/reset-password', [ServiceProviderUserController::class, 'resetPassword'])->name('provider-users.reset-password');

    // Transport Provider Module
    // Admin-side transport pages are gated by the `transport-*` permission set.
    // (store/update also re-check inside their FormRequests.) The QR scan
    // terminal is available to any authenticated staff user, mirroring the
    // cafeteria scan terminal.
    Route::prefix('transport')->name('transport.')->group(function (): void {
        Route::get('/providers', [TransportProviderController::class, 'index'])->middleware('can:transport-providers.viewAny')->name('providers.index');
        Route::get('/providers/create', [TransportProviderController::class, 'create'])->middleware('can:transport-providers.create')->name('providers.create');
        Route::post('/providers', [TransportProviderController::class, 'store'])->name('providers.store');
        Route::get('/providers/{provider}', [TransportProviderController::class, 'show'])->middleware('can:transport-providers.view')->name('providers.show');
        Route::get('/providers/{provider}/edit', [TransportProviderController::class, 'edit'])->middleware('can:transport-providers.update')->name('providers.edit');
        Route::match(['put', 'patch'], '/providers/{provider}', [TransportProviderController::class, 'update'])->name('providers.update');
        Route::delete('/providers/{provider}', [TransportProviderController::class, 'destroy'])->name('providers.destroy');
        Route::get('/scan', [TransportScanController::class, 'index'])->name('scan');
        Route::post('/scan', [TransportScanController::class, 'store'])->middleware('throttle:60,1')->name('scan.store');
        Route::get('/settings', [TransportSettingsController::class, 'index'])->middleware('can:transport-settings.view')->name('settings.index');
        Route::patch('/settings', [TransportSettingsController::class, 'update'])->middleware('can:transport-settings.update')->name('settings.update');
        Route::get('/routes', [TransportRouteController::class, 'index'])->middleware('can:transport-routes.viewAny')->name('routes.index');
        Route::get('/routes/create', [TransportRouteController::class, 'create'])->middleware('can:transport-routes.create')->name('routes.create');
        Route::post('/routes', [TransportRouteController::class, 'store'])->name('routes.store');
        Route::get('/routes/{route}/edit', [TransportRouteController::class, 'edit'])->middleware('can:transport-routes.update')->name('routes.edit');
        Route::patch('/routes/{route}', [TransportRouteController::class, 'update'])->name('routes.update');
        Route::get('/vehicles', [TransportVehicleController::class, 'index'])->middleware('can:transport-vehicles.viewAny')->name('vehicles.index');
        Route::get('/vehicles/create', [TransportVehicleController::class, 'create'])->middleware('can:transport-vehicles.create')->name('vehicles.create');
        Route::post('/vehicles', [TransportVehicleController::class, 'store'])->name('vehicles.store');
        Route::get('/vehicles/{vehicle}/edit', [TransportVehicleController::class, 'edit'])->middleware('can:transport-vehicles.update')->name('vehicles.edit');
        Route::patch('/vehicles/{vehicle}', [TransportVehicleController::class, 'update'])->name('vehicles.update');
        Route::get('/drivers', [TransportDriverController::class, 'index'])->middleware('can:transport-drivers.viewAny')->name('drivers.index');
        Route::get('/drivers/create', [TransportDriverController::class, 'create'])->middleware('can:transport-drivers.create')->name('drivers.create');
        Route::post('/drivers', [TransportDriverController::class, 'store'])->name('drivers.store');
        Route::get('/drivers/{driver}/edit', [TransportDriverController::class, 'edit'])->middleware('can:transport-drivers.update')->name('drivers.edit');
        Route::patch('/drivers/{driver}', [TransportDriverController::class, 'update'])->name('drivers.update');
        Route::get('/passes', [TransportPassController::class, 'index'])->middleware('can:transport-passes.viewAny')->name('passes.index');
        Route::get('/passes/create', [TransportPassController::class, 'create'])->middleware('can:transport-passes.create')->name('passes.create');
        Route::post('/passes', [TransportPassController::class, 'store'])->name('passes.store');
        Route::get('/passes/{pass}/edit', [TransportPassController::class, 'edit'])->middleware('can:transport-passes.update')->name('passes.edit');
        Route::patch('/passes/{pass}', [TransportPassController::class, 'update'])->name('passes.update');
        Route::get('/reports', [TransportReportController::class, 'index'])->middleware('can:transport-reports.view')->name('reports.index');
    });

    // Institution Offices — deprecated; GET routes redirect to Organization Units.
    // POST /institution-offices (store) remains active: creates an OrganizationUnit.
    Route::get('/institution-offices', [InstitutionOfficeController::class, 'index'])->name('institution-offices.index');
    Route::get('/institution-offices/create', [InstitutionOfficeController::class, 'create'])->name('institution-offices.create');
    Route::post('/institution-offices', [InstitutionOfficeController::class, 'store'])->name('institution-offices.store');
    Route::get('/institution-offices/{institutionOffice}', [InstitutionOfficeController::class, 'show'])->name('institution-offices.show')->where('institutionOffice', '[0-9a-f\-]{36}');
    Route::get('/institution-offices/{institutionOffice}/edit', [InstitutionOfficeController::class, 'edit'])->name('institution-offices.edit')->where('institutionOffice', '[0-9a-f\-]{36}');
    Route::patch('/institution-offices/{institutionOffice}', [InstitutionOfficeController::class, 'update'])->name('institution-offices.update')->where('institutionOffice', '[0-9a-f\-]{36}');
    Route::delete('/institution-offices/{institutionOffice}', [InstitutionOfficeController::class, 'destroy'])->name('institution-offices.destroy')->where('institutionOffice', '[0-9a-f\-]{36}');
    Route::post('/institution-offices/{institutionOffice}/restore', [InstitutionOfficeController::class, 'restore'])->name('institution-offices.restore')->where('institutionOffice', '[0-9a-f\-]{36}');
    Route::post('/institution-offices/{institutionOffice}/move', [InstitutionOfficeController::class, 'move'])->name('institution-offices.move')->where('institutionOffice', '[0-9a-f\-]{36}');
    // Legacy institution office relationship routes — redirect to org-unit index
    Route::get('/institution-offices/{institutionOffice}/relationships', fn () => redirect()->route('organization-units.index'))->name('institution-offices.relationships.index');
    Route::post('/institution-offices/{institutionOffice}/relationships', fn () => redirect()->route('organization-units.index'))->name('institution-offices.relationships.store');
    Route::get('/institution-offices/{institutionOffice}/relationships/{relationship}', fn () => redirect()->route('organization-units.index'))->name('institution-offices.relationships.show');
    Route::match(['put', 'patch'], '/institution-offices/{institutionOffice}/relationships/{relationship}', fn () => redirect()->route('organization-units.index'))->name('institution-offices.relationships.update');
    Route::delete('/institution-offices/{institutionOffice}/relationships/{relationship}', fn () => redirect()->route('organization-units.index'))->name('institution-offices.relationships.destroy');
    Route::post('/institution-offices/{institutionOffice}/relationships/{relationship}/restore', fn () => redirect()->route('organization-units.index'))->name('institution-offices.relationships.restore');
    // Legacy institution sub-routes — redirect to org-units
    Route::get('/institutions/{organization}/offices', fn () => redirect()->route('organization-units.index'))->name('institutions.offices.index');
    Route::get('/institutions/{organization}/offices/tree', fn ($organization) => redirect()->route('organizations.units.tree', $organization))->name('institutions.offices.tree');
    Route::get('/institutions/{organization}/offices/parent-options', fn ($organization) => redirect()->route('organizations.units.options', $organization))->name('institutions.offices.parent-options');

    Route::get('/reporting-lines', [ReportingLineController::class, 'index'])->name('reporting-lines.index');
    Route::get('/reporting-lines/organizations/{organization}', [ReportingLineController::class, 'organization'])->name('reporting-lines.organizations.show');
    Route::get('/reporting-lines/institution-offices/{institutionOffice}', [ReportingLineController::class, 'institutionOffice'])->name('reporting-lines.institution-offices.show');
    Route::get('/reporting-lines/organization-units/{organizationUnit}', [ReportingLineController::class, 'organizationUnit'])->name('reporting-lines.organization-units.show');

    // Cafeteria Subsidy Module
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

        // Providers
        Route::get('/providers', [CafeteriaProviderController::class, 'index'])->name('providers.index');
        Route::get('/providers/create', [CafeteriaProviderController::class, 'create'])->name('providers.create');
        Route::post('/providers', [CafeteriaProviderController::class, 'store'])->name('providers.store');
        Route::get('/providers/{cafeteriaProvider}', [CafeteriaProviderController::class, 'show'])->name('providers.show');
        Route::get('/providers/{cafeteriaProvider}/edit', [CafeteriaProviderController::class, 'edit'])->name('providers.edit');
        Route::patch('/providers/{cafeteriaProvider}', [CafeteriaProviderController::class, 'update'])->name('providers.update');
        Route::delete('/providers/{cafeteriaProvider}', [CafeteriaProviderController::class, 'archive'])->name('providers.archive');
        Route::post('/providers/{cafeteriaProvider}/restore', [CafeteriaProviderController::class, 'restore'])->name('providers.restore');

        // Cafeteria Provider Users (admin CRUD — stored in cafeteria_provider_users, not users table)
        Route::get('/providers/{cafeteriaProvider}/users', [CafeteriaProviderUserController::class, 'index'])->name('providers.users.index');
        Route::get('/providers/{cafeteriaProvider}/users/create', [CafeteriaProviderUserController::class, 'create'])->name('providers.users.create');
        Route::post('/providers/{cafeteriaProvider}/users', [CafeteriaProviderUserController::class, 'store'])->name('providers.users.store');
        Route::get('/providers/{cafeteriaProvider}/users/{providerUser}/edit', [CafeteriaProviderUserController::class, 'edit'])->name('providers.users.edit');
        Route::patch('/providers/{cafeteriaProvider}/users/{providerUser}', [CafeteriaProviderUserController::class, 'update'])->name('providers.users.update');
        Route::post('/providers/{cafeteriaProvider}/users/{providerUser}/reset-password', [CafeteriaProviderUserController::class, 'resetPassword'])->name('providers.users.reset-password');
        Route::post('/providers/{cafeteriaProvider}/users/{providerUser}/suspend', [CafeteriaProviderUserController::class, 'suspend'])->name('providers.users.suspend');
        Route::post('/providers/{cafeteriaProvider}/users/{providerUser}/activate', [CafeteriaProviderUserController::class, 'activate'])->name('providers.users.activate');
        Route::delete('/providers/{cafeteriaProvider}/users/{providerUser}', [CafeteriaProviderUserController::class, 'destroy'])->name('providers.users.destroy');

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

        // Employee Cafeteria Exclusions
        Route::get('/employee-exclusions', [EmployeeCafeteriaExclusionController::class, 'index'])->name('employee-exclusions.index');
        Route::get('/employee-exclusions/create', [EmployeeCafeteriaExclusionController::class, 'create'])->name('employee-exclusions.create');
        Route::post('/employee-exclusions', [EmployeeCafeteriaExclusionController::class, 'store'])->name('employee-exclusions.store');
        Route::get('/employee-exclusions/{employeeCafeteriaExclusion}', [EmployeeCafeteriaExclusionController::class, 'show'])->name('employee-exclusions.show');
        Route::get('/employee-exclusions/{employeeCafeteriaExclusion}/edit', [EmployeeCafeteriaExclusionController::class, 'edit'])->name('employee-exclusions.edit');
        Route::patch('/employee-exclusions/{employeeCafeteriaExclusion}', [EmployeeCafeteriaExclusionController::class, 'update'])->name('employee-exclusions.update');
        Route::post('/employee-exclusions/{employeeCafeteriaExclusion}/end', [EmployeeCafeteriaExclusionController::class, 'end'])->name('employee-exclusions.end');
        Route::delete('/employee-exclusions/{employeeCafeteriaExclusion}', [EmployeeCafeteriaExclusionController::class, 'archive'])->name('employee-exclusions.archive');
    });
});

// ── Grievance Module ───────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'mfa', 'admin.access'])->group(function (): void {
    // My Grievances (any authenticated user)
    /*
     * Client Service Feedback — administrative review.
     *
     * Mounted under /feedback-admin, NOT under /service-feedback. The public
     * QR route is `/service-feedback/{token}` and matches any single segment,
     * so an admin route sharing that prefix would either be shadowed by the
     * public page or, if ordered first, swallow real QR scans. A separate
     * prefix keeps the two URL spaces from ever overlapping.
     */
    /*
     * Feedback services per position.
     *
     * Unrelated to /service-types, which manages entitlements. These rows are
     * the services each POSITION delivers to clients, and they drive the
     * public feedback dropdown.
     */
    Route::get('/position-services', [PositionServiceController::class, 'index'])->name('position-services.index');
    Route::get('/position-services/create', [PositionServiceController::class, 'create'])->name('position-services.create');
    Route::get('/position-services/positions', [PositionServiceController::class, 'positionsForOrganization'])->name('position-services.positions');
    Route::post('/position-services', [PositionServiceController::class, 'store'])->name('position-services.store');
    Route::get('/position-services/{positionService}/edit', [PositionServiceController::class, 'edit'])->name('position-services.edit');
    Route::patch('/position-services/{positionService}', [PositionServiceController::class, 'update'])->name('position-services.update');
    Route::delete('/position-services/{positionService}', [PositionServiceController::class, 'destroy'])->name('position-services.destroy');

    Route::get('/feedback-admin/dashboard', [ServiceFeedbackController::class, 'dashboard'])->name('service-feedback.admin.dashboard');
    Route::get('/feedback-admin/reports', [ServiceFeedbackController::class, 'reports'])->name('service-feedback.admin.reports');
    Route::get('/feedback-admin/export', [ServiceFeedbackController::class, 'export'])->name('service-feedback.admin.export');
    Route::get('/feedback-admin', [ServiceFeedbackController::class, 'index'])->name('service-feedback.admin.index');
    Route::get('/feedback-admin/{serviceFeedback}', [ServiceFeedbackController::class, 'show'])->name('service-feedback.admin.show');
    Route::post('/feedback-admin/{serviceFeedback}/review', [ServiceFeedbackController::class, 'review'])->name('service-feedback.admin.review');
    Route::post('/feedback-admin/{serviceFeedback}/hide', [ServiceFeedbackController::class, 'hide'])->name('service-feedback.admin.hide');
    Route::delete('/feedback-admin/{serviceFeedback}', [ServiceFeedbackController::class, 'destroy'])->name('service-feedback.admin.destroy');

    // Per-employee feedback QR management.
    Route::get('/employees/{employee}/feedback-qr', [EmployeeFeedbackQrController::class, 'show'])->name('employees.feedback-qr.show');
    Route::post('/employees/{employee}/feedback-qr', [EmployeeFeedbackQrController::class, 'generate'])->name('employees.feedback-qr.generate');
    Route::post('/employees/{employee}/feedback-qr/regenerate', [EmployeeFeedbackQrController::class, 'regenerate'])->name('employees.feedback-qr.regenerate');
    Route::post('/employees/{employee}/feedback-qr/revoke', [EmployeeFeedbackQrController::class, 'revoke'])->name('employees.feedback-qr.revoke');
    Route::get('/employees/{employee}/feedback-qr/png', [EmployeeFeedbackQrController::class, 'exportPng'])->name('employees.feedback-qr.png');
    Route::get('/employees/{employee}/feedback-qr/pdf', [EmployeeFeedbackQrController::class, 'exportPdf'])->name('employees.feedback-qr.pdf');

    Route::get('/grievances/my', [GrievanceController::class, 'myGrievances'])->name('grievances.my');
    Route::get('/grievances/create', [GrievanceController::class, 'create'])->name('grievances.create');
    Route::post('/grievances', [GrievanceController::class, 'store'])->name('grievances.store');

    // Grievances (admin/committee/manager views)
    Route::get('/grievances', [GrievanceController::class, 'index'])->name('grievances.index');
    Route::get('/grievances/{grievance}', [GrievanceController::class, 'show'])->name('grievances.show');
    Route::post('/grievances/{grievance}/assign', [GrievanceController::class, 'assign'])->name('grievances.assign');
    Route::post('/grievances/{grievance}/check-requirement', [GrievanceController::class, 'checkRequirement'])->name('grievances.check-requirement');
    Route::post('/grievances/{grievance}/compile-response', [GrievanceController::class, 'compileResponse'])->name('grievances.compile-response');
    Route::post('/grievances/{grievance}/approve-response', [GrievanceController::class, 'approveResponse'])->name('grievances.approve-response');
    Route::post('/grievances/{grievance}/reject-response', [GrievanceController::class, 'rejectResponse'])->name('grievances.reject-response');
    Route::get('/grievances/{grievance}/letter', [GrievanceController::class, 'downloadLetter'])->name('grievances.letter');

    // Grievance Categories
    Route::get('/grievance-categories', [GrievanceCategoryController::class, 'index'])->name('grievance-categories.index');
    Route::post('/grievance-categories', [GrievanceCategoryController::class, 'store'])->name('grievance-categories.store');
    Route::patch('/grievance-categories/{grievanceCategory}', [GrievanceCategoryController::class, 'update'])->name('grievance-categories.update');

    // Grievance Committees
    Route::get('/grievance-committees', [GrievanceCommitteeController::class, 'index'])->name('grievance-committees.index');
    Route::get('/grievance-committees/create', [GrievanceCommitteeController::class, 'create'])->name('grievance-committees.create');
    Route::post('/grievance-committees', [GrievanceCommitteeController::class, 'store'])->name('grievance-committees.store');
    Route::get('/grievance-committees/{grievanceCommittee}', [GrievanceCommitteeController::class, 'show'])->name('grievance-committees.show');
    Route::post('/grievance-committees/{grievanceCommittee}/members', [GrievanceCommitteeController::class, 'addMember'])->name('grievance-committees.members.add');
    Route::delete('/grievance-committees/{grievanceCommittee}/members/{member}', [GrievanceCommitteeController::class, 'removeMember'])->name('grievance-committees.members.remove');
    Route::delete('/grievance-committees/{grievanceCommittee}', [GrievanceCommitteeController::class, 'destroy'])->name('grievance-committees.destroy');

    // Grievance SLA Rules
    Route::get('/grievance-sla-rules', [GrievanceSlaRuleController::class, 'index'])->name('grievance-sla-rules.index');
    Route::post('/grievance-sla-rules', [GrievanceSlaRuleController::class, 'store'])->name('grievance-sla-rules.store');
    Route::patch('/grievance-sla-rules/{grievanceSlaRule}', [GrievanceSlaRuleController::class, 'update'])->name('grievance-sla-rules.update');
    Route::delete('/grievance-sla-rules/{grievanceSlaRule}', [GrievanceSlaRuleController::class, 'destroy'])->name('grievance-sla-rules.destroy');

    // Administrative Tribunal
    Route::get('/tribunal-cases', [AdministrativeTribunalController::class, 'index'])->name('tribunal-cases.index');
    Route::get('/tribunal-cases/{administrativeTribunalCase}', [AdministrativeTribunalController::class, 'show'])->name('tribunal-cases.show');
    Route::patch('/tribunal-cases/{administrativeTribunalCase}', [AdministrativeTribunalController::class, 'update'])->name('tribunal-cases.update');
});

Route::middleware(['auth', 'admin.access'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
