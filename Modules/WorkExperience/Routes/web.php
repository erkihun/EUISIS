<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WorkExperience\Http\Controllers\Employee\MyExperienceController;
use Modules\WorkExperience\Http\Controllers\RecordController;
use Modules\WorkExperience\Http\Controllers\RequestController;
use Modules\WorkExperience\Http\Controllers\SettingsController;

/*
|--------------------------------------------------------------------------
| Work experience — ADR 0040, Modules/WorkExperience/README.md
|--------------------------------------------------------------------------
| Loaded by the module's provider inside the "web" group. Route middleware
| authenticates; every action re-checks its permission, the record and the
| organization scope (WorkExperienceAccess). The feature flag
| "work_experience" (config/features.php, route prefixes "work-experience."
| and "employee.work-experience.") turns every route into a 404.
*/

// My Portal > My work experience. No route here accepts an employee id: it is always the user's own record.
Route::middleware(['auth', 'force.password', 'admin.access'])
    ->prefix('my-portal/work-experience')->name('employee.work-experience.')
    ->controller(MyExperienceController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::post('/{experienceRequest}/cancel', 'cancel')->whereUuid('experienceRequest')->middleware('throttle:30,1')->name('cancel');
    });

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('work-experience')->name('work-experience.')
    ->group(function (): void {
        // The record of each employee's experience.
        Route::get('/', [RecordController::class, 'index'])->name('records.index');
        Route::post('/employees/{employee}/records', [RecordController::class, 'store'])->whereUuid('employee')->middleware('throttle:60,1')->name('records.store');
        // POST, not PUT: the form may carry a file.
        Route::post('/records/{experienceRecord}', [RecordController::class, 'update'])->whereUuid('experienceRecord')->middleware('throttle:60,1')->name('records.update');
        Route::delete('/records/{experienceRecord}', [RecordController::class, 'destroy'])->whereUuid('experienceRecord')->middleware('throttle:60,1')->name('records.destroy');
        Route::get('/records/{experienceRecord}/evidence', [RecordController::class, 'evidence'])->whereUuid('experienceRecord')->middleware('throttle:60,1')->name('records.evidence');

        // Requests for a certificate.
        Route::get('/requests', [RequestController::class, 'index'])->name('requests.index');
        Route::post('/requests', [RequestController::class, 'store'])->middleware('throttle:30,1')->name('requests.store');
        Route::post('/requests/{experienceRequest}/accept', [RequestController::class, 'accept'])->whereUuid('experienceRequest')->middleware('throttle:60,1')->name('requests.accept');
        Route::post('/requests/{experienceRequest}/return', [RequestController::class, 'sendBack'])->whereUuid('experienceRequest')->middleware('throttle:60,1')->name('requests.return');
        Route::post('/requests/{experienceRequest}/rewrite', [RequestController::class, 'rewrite'])->whereUuid('experienceRequest')->middleware('throttle:60,1')->name('requests.rewrite');
        Route::post('/requests/{experienceRequest}/sent', [RequestController::class, 'markSent'])->whereUuid('experienceRequest')->middleware('throttle:60,1')->name('requests.sent');
        Route::get('/requests/{experienceRequest}/print', [RequestController::class, 'print'])->whereUuid('experienceRequest')->middleware('throttle:60,1')->name('requests.print');

        // Who signs, and what the certificate carries, per institution.
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::put('/settings/{organization}', [SettingsController::class, 'update'])->whereUuid('organization')->name('settings.update');
    });
