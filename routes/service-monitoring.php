<?php

declare(strict_types=1);

use App\Http\Controllers\ServiceMonitoring\ServiceMonitoringFormController;
use App\Http\Controllers\ServiceMonitoring\ServiceMonitoringPeriodController;
use App\Http\Controllers\ServiceMonitoring\ServiceMonitoringReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Service Delivery Monitoring — docs/service-monitoring.md
|--------------------------------------------------------------------------
| Route middleware authenticates; every action re-checks its policy, and
| report actions also check the institution against the organization scope.
*/
Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('service-monitoring')->name('service-monitoring.')
    ->group(function (): void {
        // The bare module URL lands on the first page the user may open.
        Route::get('/', [ServiceMonitoringReportController::class, 'home'])->name('index');

        // Form builder. Static paths come before {form}.
        Route::controller(ServiceMonitoringFormController::class)->prefix('forms')->name('forms.')->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
            Route::get('/{form}', 'show')->whereUuid('form')->name('show');
            Route::get('/{form}/edit', 'edit')->whereUuid('form')->name('edit');
            Route::put('/{form}', 'update')->whereUuid('form')->middleware('throttle:60,1')->name('update');
            Route::delete('/{form}', 'destroy')->whereUuid('form')->middleware('throttle:30,1')->name('destroy');
            Route::post('/{form}/publish', 'publish')->whereUuid('form')->middleware('throttle:30,1')->name('publish');
            Route::post('/{form}/new-version', 'newVersion')->whereUuid('form')->middleware('throttle:30,1')->name('new-version');
            Route::post('/{form}/duplicate', 'duplicate')->whereUuid('form')->middleware('throttle:30,1')->name('duplicate');
            Route::post('/{form}/archive', 'archive')->whereUuid('form')->middleware('throttle:30,1')->name('archive');
        });

        Route::controller(ServiceMonitoringPeriodController::class)->prefix('periods')->name('periods.')->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
            Route::get('/{period}/edit', 'edit')->whereUuid('period')->name('edit');
            Route::put('/{period}', 'update')->whereUuid('period')->middleware('throttle:30,1')->name('update');
        });

        Route::controller(ServiceMonitoringReportController::class)->group(function (): void {
            Route::get('/summary', 'summary')->name('summary');
            Route::get('/reports', 'index')->name('reports.index');
            Route::get('/reports/organizations', 'organizations')->middleware('throttle:60,1')->name('reports.organizations');
            Route::post('/reports', 'start')->middleware('throttle:30,1')->name('reports.start');
            Route::get('/reports/{submission}', 'show')->whereUuid('submission')->name('reports.show');
            Route::get('/reports/{submission}/edit', 'edit')->whereUuid('submission')->name('reports.edit');
            // POST (not PUT) so the stamped copy can travel as multipart.
            Route::post('/reports/{submission}', 'update')->whereUuid('submission')->middleware('throttle:60,1')->name('reports.update');
            Route::post('/reports/{submission}/review', 'review')->whereUuid('submission')->middleware('throttle:30,1')->name('reports.review');
            Route::get('/reports/{submission}/attachment', 'attachment')->whereUuid('submission')->middleware('throttle:60,1')->name('reports.attachment');
        });
    });
