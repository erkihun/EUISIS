<?php

declare(strict_types=1);

use CafeteriaSystem\Http\Controllers\AuthController;
use CafeteriaSystem\Http\Controllers\DashboardController;
use CafeteriaSystem\Http\Controllers\LedgerController;
use CafeteriaSystem\Http\Controllers\ManagementController;
use CafeteriaSystem\Http\Controllers\ProfileController;
use CafeteriaSystem\Http\Controllers\ReportController;
use CafeteriaSystem\Http\Controllers\ScanController;
use CafeteriaSystem\Http\Controllers\SettlementController;
use CafeteriaSystem\Http\Controllers\TransactionController;
use CafeteriaSystem\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Cafeteria System routes.
 *
 * Guard: `cafeteria` — this application's own auth. EUISIS credentials do not
 * work here, and these accounts have no access to EUISIS admin.
 */
Route::middleware('guest:cafeteria')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth:cafeteria')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Scan + manual token entry share one verification pipeline.
    Route::get('/scan', [ScanController::class, 'index'])->name('scan.index');
    Route::post('/scan/verify', [ScanController::class, 'verify'])
        ->middleware('throttle:cafeteria-scan')
        ->name('scan.verify');

    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
    Route::post('/settlements', [SettlementController::class, 'store'])->name('settlements.store');

    Route::get('/api-logs', [DashboardController::class, 'apiLogs'])->name('api-logs.index');
    Route::get('/settings', [DashboardController::class, 'settings'])->name('settings.index');

    // Provider-scoped management. Every action re-checks the acting user's
    // provider and cafeteria scope inside the controller.
    Route::get('/cafeterias', [ManagementController::class, 'cafeterias'])->name('cafeterias.index');
    Route::post('/cafeterias', [ManagementController::class, 'storeCafeteria'])->name('cafeterias.store');

    Route::get('/assignments', [ManagementController::class, 'assignments'])->name('assignments.index');
    Route::get('/assignments/organization-lookup', [ManagementController::class, 'organizationLookup'])->name('assignments.organization-lookup');
    Route::post('/assignments', [ManagementController::class, 'storeAssignment'])->name('assignments.store');
    Route::delete('/assignments/{assignment}', [ManagementController::class, 'destroyAssignment'])->name('assignments.destroy');

    Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
    Route::get('/providers', [ManagementController::class, 'providers'])->name('providers.index');
    Route::patch('/providers', [ManagementController::class, 'updateProvider'])->name('providers.update');
    Route::get('/cafeteria-settings', [ManagementController::class, 'settings'])->name('cafeteria-settings.index');
    Route::patch('/cafeteria-settings', [ManagementController::class, 'updateSettings'])->name('cafeteria-settings.update');

    // The operator's own account: name, email and password only.
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::get('/users', [ManagementController::class, 'users'])->name('users.index');
    Route::post('/users', [ManagementController::class, 'storeUser'])->name('users.store');
});

/*
 * Language switch. Deliberately outside the auth group so the login page can
 * also be read in Amharic — an operator who cannot read the form cannot log in
 * to change the setting.
 */
Route::post('/locale', function (Request $request) {
    $locale = (string) $request->input('locale');

    if (in_array($locale, SetLocale::SUPPORTED, true)) {
        $request->session()->put('locale', $locale);
    }

    return back();
})->name('locale.switch');
