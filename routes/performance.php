<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Performance\Http\Controllers\Employee\MyPerformanceController;
use Modules\Performance\Http\Controllers\EmployeeAgreementController;
use Modules\Performance\Http\Controllers\KpiController;
use Modules\Performance\Http\Controllers\PerformanceAppealController;
use Modules\Performance\Http\Controllers\PerformanceCalibrationController;
use Modules\Performance\Http\Controllers\PerformanceCycleController;
use Modules\Performance\Http\Controllers\PerformanceDashboardController;
use Modules\Performance\Http\Controllers\PerformanceLookupController;
use Modules\Performance\Http\Controllers\PerformancePlanController;
use Modules\Performance\Http\Controllers\PerformanceReportController;
use Modules\Performance\Http\Controllers\PerformanceSettingController;
use Modules\Performance\Http\Controllers\StrategicGoalController;
use Modules\Performance\Http\Controllers\StrategyHierarchyController;
use Modules\Performance\Http\Middleware\EnsurePerformanceEnabled;

/*
|--------------------------------------------------------------------------
| Employee Performance Management (EPMS) — docs/epms-architecture.md
|--------------------------------------------------------------------------
| Route middleware authenticates; every action is authorized again in the
| EPMS services (permission + organization scope / manager coverage / own).
| No external API routes: performance data is not exposed by the API.
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access', EnsurePerformanceEnabled::class])
    ->prefix('performance')->name('performance.')
    ->group(function (): void {
        Route::get('/', [PerformanceDashboardController::class, 'index'])->name('dashboard');

        Route::get('/cycles', [PerformanceCycleController::class, 'index'])->name('cycles.index');
        Route::post('/cycles', [PerformanceCycleController::class, 'store'])->name('cycles.store');
        Route::put('/cycles/{cycle}', [PerformanceCycleController::class, 'update'])->whereUuid('cycle')->name('cycles.update');
        Route::post('/cycles/{cycle}/transition', [PerformanceCycleController::class, 'transition'])->whereUuid('cycle')->name('cycles.transition');

        Route::get('/strategic-goals', [StrategicGoalController::class, 'index'])->name('strategic-goals.index');
        Route::post('/strategic-goals', [StrategicGoalController::class, 'store'])->name('strategic-goals.store');
        Route::put('/strategic-goals/{strategicGoal}', [StrategicGoalController::class, 'update'])->whereUuid('strategicGoal')->name('strategic-goals.update');
        Route::delete('/strategic-goals/{strategicGoal}', [StrategicGoalController::class, 'destroy'])->whereUuid('strategicGoal')->name('strategic-goals.destroy');
        Route::post('/strategic-goals/{strategicGoal}/allocations', [StrategicGoalController::class, 'storeAllocation'])->whereUuid('strategicGoal')->name('strategic-goals.allocations.store');
        Route::put('/strategic-goal-allocations/{allocation}', [StrategicGoalController::class, 'updateAllocation'])->whereUuid('allocation')->name('strategic-goal-allocations.update');
        Route::delete('/strategic-goal-allocations/{allocation}', [StrategicGoalController::class, 'destroyAllocation'])->whereUuid('allocation')->name('strategic-goal-allocations.destroy');
        Route::post('/strategic-goals/{strategicGoal}/transition', [StrategicGoalController::class, 'transition'])->whereUuid('strategicGoal')->name('strategic-goals.transition');
        Route::post('/strategic-goals/{strategicGoal}/return', [StrategicGoalController::class, 'returnToDraft'])->whereUuid('strategicGoal')->name('strategic-goals.return');
        Route::post('/strategic-goals/{strategicGoal}/versions', [StrategicGoalController::class, 'newVersion'])->whereUuid('strategicGoal')->name('strategic-goals.versions.store');

        // Strategic overview: main strategy → strategy → goal → objective → activity (ADR 0019).
        Route::get('/strategy', [StrategyHierarchyController::class, 'index'])->name('strategy.index');
        Route::get('/strategy/main-strategies/create', [StrategyHierarchyController::class, 'createMain'])->name('strategy.main.create');
        Route::post('/strategy/main-strategies', [StrategyHierarchyController::class, 'storeMain'])->name('strategy.main.store');
        Route::get('/strategy/main-strategies/{mainStrategy}/edit', [StrategyHierarchyController::class, 'editMain'])->whereUuid('mainStrategy')->name('strategy.main.edit');
        Route::put('/strategy/main-strategies/{mainStrategy}', [StrategyHierarchyController::class, 'updateMain'])->whereUuid('mainStrategy')->name('strategy.main.update');
        Route::delete('/strategy/main-strategies/{mainStrategy}', [StrategyHierarchyController::class, 'destroyMain'])->whereUuid('mainStrategy')->name('strategy.main.destroy');
        Route::get('/strategy/goals/create', [StrategyHierarchyController::class, 'createGoal'])->name('strategy.goals.create');
        Route::post('/strategy/goals', [StrategyHierarchyController::class, 'storeGoal'])->name('strategy.goals.store');
        Route::get('/strategy/goals/{strategicGoal}/edit', [StrategyHierarchyController::class, 'editGoal'])->whereUuid('strategicGoal')->name('strategy.goals.edit');
        Route::put('/strategy/goals/{strategicGoal}', [StrategyHierarchyController::class, 'updateGoal'])->whereUuid('strategicGoal')->name('strategy.goals.update');

        Route::get('/kpis', [KpiController::class, 'index'])->name('kpis.index');
        Route::post('/kpis', [KpiController::class, 'store'])->name('kpis.store');
        Route::put('/kpis/{kpi}', [KpiController::class, 'update'])->whereUuid('kpi')->name('kpis.update');

        Route::get('/plans', [PerformancePlanController::class, 'index'])->name('plans.index');
        Route::post('/plans', [PerformancePlanController::class, 'store'])->name('plans.store');
        Route::get('/plans/{plan}', [PerformancePlanController::class, 'show'])->whereUuid('plan')->name('plans.show');
        Route::post('/plans/{plan}/objectives', [PerformancePlanController::class, 'storeObjective'])->whereUuid('plan')->name('plans.objectives.store');
        Route::put('/objectives/{objective}', [PerformancePlanController::class, 'updateObjective'])->whereUuid('objective')->name('objectives.update');
        Route::delete('/objectives/{objective}', [PerformancePlanController::class, 'destroyObjective'])->whereUuid('objective')->name('objectives.destroy');
        Route::post('/objectives/{objective}/targets', [PerformancePlanController::class, 'storeTarget'])->whereUuid('objective')->name('objectives.targets.store');
        Route::put('/targets/{target}', [PerformancePlanController::class, 'updateTarget'])->whereUuid('target')->name('targets.update');
        Route::delete('/targets/{target}', [PerformancePlanController::class, 'destroyTarget'])->whereUuid('target')->name('targets.destroy');
        Route::put('/targets/{target}/period-targets', [StrategicGoalController::class, 'replacePeriodTargets'])->whereUuid('target')->name('targets.period-targets.replace');
        Route::post('/targets/{target}/actuals', [PerformancePlanController::class, 'recordActual'])->whereUuid('target')->name('targets.actuals.store');
        Route::post('/targets/{target}/amendments', [PerformancePlanController::class, 'requestAmendment'])->whereUuid('target')->name('targets.amendments.store');
        Route::post('/plans/{plan}/cascade', [PerformancePlanController::class, 'cascade'])->whereUuid('plan')->name('plans.cascade');
        Route::post('/plans/{plan}/decline', [PerformancePlanController::class, 'decline'])->whereUuid('plan')->name('plans.decline');
        Route::post('/plans/{plan}/versions', [PerformancePlanController::class, 'newVersion'])->whereUuid('plan')->name('plans.versions.store');
        Route::post('/plans/{plan}/recalculate', [PerformancePlanController::class, 'recalculate'])->whereUuid('plan')->middleware('throttle:10,1')->name('plans.recalculate');
        Route::post('/plans/{plan}/{action}', [PerformancePlanController::class, 'workflow'])->whereUuid('plan')->whereIn('action', ['submit', 'return', 'approve', 'publish'])->name('plans.workflow');
        Route::post('/amendments/{amendment}/decide', [PerformancePlanController::class, 'decideAmendment'])->whereUuid('amendment')->name('amendments.decide');

        Route::get('/agreements', [EmployeeAgreementController::class, 'index'])->name('agreements.index');
        Route::post('/agreements', [EmployeeAgreementController::class, 'store'])->middleware('throttle:30,1')->name('agreements.store');
        Route::get('/agreements/{agreement}', [EmployeeAgreementController::class, 'show'])->whereUuid('agreement')->name('agreements.show');
        Route::post('/agreements/{agreement}/items', [EmployeeAgreementController::class, 'storeItem'])->whereUuid('agreement')->name('agreements.items.store');
        Route::put('/items/{item}', [EmployeeAgreementController::class, 'updateItem'])->whereUuid('item')->name('items.update');
        Route::delete('/items/{item}', [EmployeeAgreementController::class, 'destroyItem'])->whereUuid('item')->name('items.destroy');
        Route::post('/items/{item}/actuals', [EmployeeAgreementController::class, 'recordActual'])->whereUuid('item')->name('items.actuals.store');
        Route::post('/items/{item}/sync', [EmployeeAgreementController::class, 'syncActual'])->whereUuid('item')->middleware('throttle:30,1')->name('items.sync');
        Route::post('/items/{item}/amendments', [EmployeeAgreementController::class, 'requestAmendment'])->whereUuid('item')->name('items.amendments.store');
        Route::post('/actuals/{actual}/verify', [EmployeeAgreementController::class, 'verifyActual'])->whereUuid('actual')->name('actuals.verify');
        Route::post('/agreements/{agreement}/transfer', [EmployeeAgreementController::class, 'transfer'])->whereUuid('agreement')->name('agreements.transfer');
        Route::post('/agreements/{agreement}/evidence', [EmployeeAgreementController::class, 'storeEvidence'])->whereUuid('agreement')->middleware('throttle:30,1')->name('agreements.evidence.store');
        Route::post('/evidence/{evidence}/verify', [EmployeeAgreementController::class, 'verifyEvidence'])->whereUuid('evidence')->name('evidence.verify');
        Route::post('/agreements/{agreement}/checkins', [EmployeeAgreementController::class, 'storeCheckin'])->whereUuid('agreement')->middleware('throttle:30,1')->name('agreements.checkins.store');
        Route::post('/agreements/{agreement}/reviews/{type}/complete', [EmployeeAgreementController::class, 'completeReview'])->whereUuid('agreement')->whereIn('type', ['mid_year', 'year_end'])->name('agreements.reviews.complete');
        Route::post('/agreements/{agreement}/reviews/{type}/return', [EmployeeAgreementController::class, 'returnReview'])->whereUuid('agreement')->whereIn('type', ['mid_year', 'year_end'])->name('agreements.reviews.return');
        Route::post('/agreements/{agreement}/calculate', [EmployeeAgreementController::class, 'calculate'])->whereUuid('agreement')->name('agreements.calculate');
        Route::post('/agreements/{agreement}/improvement-plans', [EmployeeAgreementController::class, 'storeImprovementPlan'])->whereUuid('agreement')->middleware('throttle:30,1')->name('agreements.pips.store');
        Route::post('/agreements/{agreement}/development-plans', [EmployeeAgreementController::class, 'storeDevelopmentPlan'])->whereUuid('agreement')->middleware('throttle:30,1')->name('agreements.idps.store');
        Route::post('/agreements/{agreement}/{action}', [EmployeeAgreementController::class, 'workflow'])->whereUuid('agreement')->whereIn('action', ['submit', 'approve', 'return', 'close'])->name('agreements.workflow');
        Route::post('/results/{result}/adjustments', [EmployeeAgreementController::class, 'requestAdjustment'])->whereUuid('result')->name('results.adjustments.store');
        Route::post('/adjustments/{adjustment}/decide', [EmployeeAgreementController::class, 'decideAdjustment'])->whereUuid('adjustment')->name('adjustments.decide');
        Route::post('/results/{result}/{action}', [EmployeeAgreementController::class, 'resultAction'])->whereUuid('result')->whereIn('action', ['finalize', 'release'])->name('results.action');
        Route::post('/item-amendments/{amendment}/decide', [EmployeeAgreementController::class, 'decideAmendment'])->whereUuid('amendment')->name('item-amendments.decide');

        Route::get('/calibration', [PerformanceCalibrationController::class, 'index'])->name('calibration.index');
        Route::post('/calibration', [PerformanceCalibrationController::class, 'store'])->middleware('throttle:30,1')->name('calibration.store');
        Route::get('/calibration/{session}', [PerformanceCalibrationController::class, 'show'])->whereUuid('session')->name('calibration.show');
        Route::post('/calibration/{session}/results', [PerformanceCalibrationController::class, 'addResults'])->whereUuid('session')->name('calibration.results.store');
        Route::post('/calibration/{session}/finalize', [PerformanceCalibrationController::class, 'finalize'])->whereUuid('session')->name('calibration.finalize');
        Route::post('/calibration-items/{item}/decide', [PerformanceCalibrationController::class, 'decide'])->whereUuid('item')->name('calibration.items.decide');

        Route::get('/appeals', [PerformanceAppealController::class, 'index'])->name('appeals.index');
        Route::get('/appeals/{appeal}', [PerformanceAppealController::class, 'show'])->whereUuid('appeal')->name('appeals.show');
        Route::post('/appeals/{appeal}/decide', [PerformanceAppealController::class, 'decide'])->whereUuid('appeal')->name('appeals.decide');

        Route::get('/reports', [PerformanceReportController::class, 'index'])->middleware('throttle:60,1')->name('reports.index');
        // Its own counter: an unprefixed throttle counts per user across every route, so browsing reports used up the export budget.
        Route::get('/reports/export', [PerformanceReportController::class, 'export'])->middleware('throttle:10,1,performance-export')->name('reports.export');

        Route::get('/settings', [PerformanceSettingController::class, 'index'])->name('settings.index');
        Route::patch('/settings', [PerformanceSettingController::class, 'update'])->name('settings.update');
        Route::put('/settings/bands/{band}', [PerformanceSettingController::class, 'updateBand'])->whereUuid('band')->name('settings.bands.update');

        // Evidence and appeal files for managers and reviewers (scope / coverage re-checked in the controller).
        Route::get('/evidence/{evidence}/download', [EmployeeAgreementController::class, 'downloadEvidence'])->whereUuid('evidence')->name('evidence.download');
        Route::get('/appeals/{appeal}/attachment', [PerformanceAppealController::class, 'attachment'])->whereUuid('appeal')->name('appeals.attachment');

        Route::prefix('lookups')->name('lookups.')->middleware('throttle:120,1')->group(function (): void {
            Route::get('/organizations', [PerformanceLookupController::class, 'organizations'])->name('organizations');
            Route::get('/units', [PerformanceLookupController::class, 'units'])->name('units');
            Route::get('/positions', [PerformanceLookupController::class, 'positions'])->name('positions');
            Route::get('/employees', [PerformanceLookupController::class, 'employees'])->name('employees');
        });
    });

// My Portal → My Performance (own records only).
Route::middleware(['auth', 'force.password', 'admin.access', EnsurePerformanceEnabled::class])->prefix('my-portal/performance')->name('employee.performance.')->group(function (): void {
    Route::get('/', [MyPerformanceController::class, 'index'])->name('index');
    Route::get('/evidence/{evidence}/download', [MyPerformanceController::class, 'downloadEvidence'])->whereUuid('evidence')->name('evidence.download');
    Route::post('/agreements/{agreement}/acknowledge', [MyPerformanceController::class, 'acknowledge'])->whereUuid('agreement')->name('acknowledge');
    Route::post('/agreements/{agreement}/return', [MyPerformanceController::class, 'returnAgreement'])->whereUuid('agreement')->name('return');
    Route::post('/agreements/{agreement}/reviews/{type}', [MyPerformanceController::class, 'submitReview'])->whereUuid('agreement')->whereIn('type', ['mid_year', 'year_end'])->name('reviews.submit');
    Route::post('/agreements/{agreement}/evidence', [MyPerformanceController::class, 'storeEvidence'])->whereUuid('agreement')->middleware('throttle:30,1')->name('evidence.store');
    Route::post('/agreements/{agreement}/development-plans', [MyPerformanceController::class, 'storeDevelopmentPlan'])->whereUuid('agreement')->middleware('throttle:30,1')->name('idps.store');
    Route::post('/checkins/{checkin}', [MyPerformanceController::class, 'checkinNote'])->whereUuid('checkin')->middleware('throttle:30,1')->name('checkins.note');
    Route::post('/results/{result}/appeal', [MyPerformanceController::class, 'appeal'])->whereUuid('result')->middleware('throttle:5,60')->name('appeal');
});
