<?php

declare(strict_types=1);

use App\Http\Controllers\Employee\FieldWorkController as EmployeeFieldWorkController;
use App\Http\Controllers\FieldWork\FieldWorkManagementController;
use App\Http\Controllers\FieldWork\FieldWorkTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Field Work Management — docs/field-work-management.md
|--------------------------------------------------------------------------
| Route middleware authenticates; every action re-checks its permission and
| the record (FieldWorkRequestPolicy), and FieldWorkService enforces the
| requester / supervisor / participant identity on every transition.
*/

/*
 * My Portal > Field Work. Same middleware as the rest of My Portal. No route
 * takes an employee id: the requester is always the signed-in user's own
 * employee record. Static paths come before {fieldWorkRequest}.
 */
Route::middleware(['auth', 'force.password', 'admin.access'])
    ->prefix('my-portal/field-work')->name('employee.field-work.')
    ->controller(EmployeeFieldWorkController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/history', 'history')->name('history');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
        Route::get('/lookup/colleagues', 'colleagues')->middleware('throttle:60,1')->name('lookup.colleagues');
        Route::get('/lookup/organizations', 'destinationOrganizations')->middleware('throttle:60,1')->name('lookup.organizations');
        Route::get('/lookup/organizations/{organization}/units', 'destinationUnits')->whereUuid('organization')->middleware('throttle:60,1')->name('lookup.units');
        Route::get('/{fieldWorkRequest}', 'show')->whereUuid('fieldWorkRequest')->name('show');
        Route::get('/{fieldWorkRequest}/edit', 'edit')->whereUuid('fieldWorkRequest')->name('edit');
        Route::put('/{fieldWorkRequest}', 'update')->whereUuid('fieldWorkRequest')->middleware('throttle:30,1')->name('update');
        Route::post('/{fieldWorkRequest}/submit', 'submit')->whereUuid('fieldWorkRequest')->middleware('throttle:30,1')->name('submit');
        Route::post('/{fieldWorkRequest}/cancel', 'cancel')->whereUuid('fieldWorkRequest')->middleware('throttle:30,1')->name('cancel');
        Route::post('/{fieldWorkRequest}/check-in', 'checkIn')->whereUuid('fieldWorkRequest')->middleware('throttle:20,1')->name('check-in');
        Route::post('/{fieldWorkRequest}/check-out', 'checkOut')->whereUuid('fieldWorkRequest')->middleware('throttle:20,1')->name('check-out');
        Route::post('/{fieldWorkRequest}/complete', 'complete')->whereUuid('fieldWorkRequest')->middleware('throttle:30,1')->name('complete');
    });

/*
 * Field Work Management (admin sidebar group). Supervisor, HR oversight and
 * configuration pages; each action re-checks its own permission.
 */
Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('field-work')->name('field-work.')
    ->group(function (): void {
        Route::get('/', [FieldWorkManagementController::class, 'dashboard'])->name('dashboard');
        Route::get('/requests', [FieldWorkManagementController::class, 'index'])->name('requests.index');
        Route::get('/pending-approval', [FieldWorkManagementController::class, 'approvals'])->name('pending');
        Route::get('/team', [FieldWorkManagementController::class, 'team'])->name('team.index');
        Route::get('/availability', [FieldWorkManagementController::class, 'availability'])->name('availability.index');
        Route::get('/overdue', [FieldWorkManagementController::class, 'overdue'])->name('overdue.index');

        Route::get('/requests/{fieldWorkRequest}', [FieldWorkManagementController::class, 'show'])->whereUuid('fieldWorkRequest')->name('requests.show');
        Route::post('/requests/{fieldWorkRequest}/approve', [FieldWorkManagementController::class, 'approve'])->whereUuid('fieldWorkRequest')->middleware('throttle:60,1')->name('requests.approve');
        Route::post('/requests/{fieldWorkRequest}/return', [FieldWorkManagementController::class, 'returnForCorrection'])->whereUuid('fieldWorkRequest')->middleware('throttle:60,1')->name('requests.return');
        Route::post('/requests/{fieldWorkRequest}/reject', [FieldWorkManagementController::class, 'reject'])->whereUuid('fieldWorkRequest')->middleware('throttle:60,1')->name('requests.reject');

        Route::get('/types', [FieldWorkTypeController::class, 'index'])->name('types.index');
        Route::post('/types', [FieldWorkTypeController::class, 'store'])->name('types.store');
        Route::put('/types/{fieldWorkType}', [FieldWorkTypeController::class, 'update'])->whereUuid('fieldWorkType')->name('types.update');
    });
