<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Leave\Http\Controllers\BalanceController;
use Modules\Leave\Http\Controllers\Employee\MyLeaveController;
use Modules\Leave\Http\Controllers\PlanController;
use Modules\Leave\Http\Controllers\ReportController;
use Modules\Leave\Http\Controllers\RequestActionController;
use Modules\Leave\Http\Controllers\RequestController;
use Modules\Leave\Http\Controllers\SettingsController;

/*
|--------------------------------------------------------------------------
| Employee leave — ADR 0039, Modules/Leave/README.md
|--------------------------------------------------------------------------
| Loaded by the module's provider inside the "web" group. Route middleware
| authenticates; every action re-checks its permission, the record and the
| organization scope (LeaveAccess). The feature flag "leave"
| (config/features.php, route prefixes "leave." and "employee.leave.") turns
| every route into a 404.
*/

// My Portal > My leave. No route here accepts an employee id: the employee is always the user's own record.
Route::middleware(['auth', 'force.password', 'admin.access'])
    ->prefix('my-portal/leave')->name('employee.leave.')
    ->controller(MyLeaveController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/request', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
        Route::get('/{leaveRequest}', 'show')->whereUuid('leaveRequest')->name('show');
        // My leave schedule for the budget year: the periods I mean to take.
        Route::post('/plans', [PlanController::class, 'store'])->middleware('throttle:30,1')->name('plans.store');
        Route::delete('/plans/{leavePlan}', [PlanController::class, 'destroy'])->whereUuid('leavePlan')->middleware('throttle:30,1')->name('plans.destroy');
    });

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('leave')->name('leave.')
    ->group(function (): void {
        // Requests: the HR register, the supervisor's inbox, one request.
        Route::get('/', [RequestController::class, 'index'])->name('requests.index');
        Route::get('/approvals', [RequestController::class, 'approvals'])->name('approvals');
        Route::get('/requests/{leaveRequest}', [RequestController::class, 'show'])->whereUuid('leaveRequest')->name('requests.show');

        // Decisions and changes. The owner uses cancel and documents too; each action checks who is asking.
        Route::post('/requests/{leaveRequest}/supervisor-decision', [RequestActionController::class, 'supervisorDecision'])->whereUuid('leaveRequest')->middleware('throttle:60,1')->name('requests.supervisor-decision');
        Route::post('/requests/{leaveRequest}/hr-decision', [RequestActionController::class, 'hrDecision'])->whereUuid('leaveRequest')->middleware('throttle:60,1')->name('requests.hr-decision');
        Route::post('/requests/{leaveRequest}/cancel', [RequestActionController::class, 'cancel'])->whereUuid('leaveRequest')->middleware('throttle:30,1')->name('requests.cancel');
        Route::post('/requests/{leaveRequest}/end-early', [RequestActionController::class, 'endEarly'])->whereUuid('leaveRequest')->middleware('throttle:30,1')->name('requests.end-early');
        Route::post('/requests/{leaveRequest}/delivery', [RequestActionController::class, 'delivery'])->whereUuid('leaveRequest')->middleware('throttle:30,1')->name('requests.delivery');
        Route::post('/requests/{leaveRequest}/documents', [RequestActionController::class, 'addDocument'])->whereUuid('leaveRequest')->middleware('throttle:30,1')->name('requests.documents.store');
        Route::get('/documents/{leaveDocument}', [RequestActionController::class, 'download'])->whereUuid('leaveDocument')->middleware('throttle:60,1')->name('documents.download');

        // Balances and the ledger.
        Route::get('/balances', [BalanceController::class, 'index'])->name('balances.index');
        Route::post('/balances/import', [BalanceController::class, 'import'])->middleware('throttle:10,1')->name('balances.import');
        Route::get('/balances/{employee}', [BalanceController::class, 'show'])->whereUuid('employee')->name('balances.show');
        Route::post('/balances/{employee}/adjust', [BalanceController::class, 'adjust'])->whereUuid('employee')->middleware('throttle:60,1')->name('balances.adjust');
        Route::post('/balances/{employee}/postpone', [BalanceController::class, 'postpone'])->whereUuid('employee')->middleware('throttle:60,1')->name('balances.postpone');

        // The leave schedule of the team (supervisor) or the institution (HR).
        Route::get('/schedule', [PlanController::class, 'index'])->name('plans.index');
        Route::post('/schedule/{leavePlan}/decision', [PlanController::class, 'decide'])->whereUuid('leavePlan')->middleware('throttle:120,1')->name('plans.decide');

        Route::get('/reports', ReportController::class)->name('reports');

        // Leave types and the legal figures.
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
        Route::post('/settings/types', [SettingsController::class, 'storeType'])->name('settings.types.store');
        Route::put('/settings/types/{leaveType}', [SettingsController::class, 'updateType'])->whereUuid('leaveType')->name('settings.types.update');
        Route::post('/settings/rule-sets', [SettingsController::class, 'storeRuleSet'])->name('settings.rule-sets.store');
        Route::put('/settings/rule-sets/{leaveRuleSet}', [SettingsController::class, 'updateRuleSet'])->whereUuid('leaveRuleSet')->name('settings.rule-sets.update');
        Route::post('/settings/rule-sets/{leaveRuleSet}/activate', [SettingsController::class, 'activateRuleSet'])->whereUuid('leaveRuleSet')->name('settings.rule-sets.activate');
    });
