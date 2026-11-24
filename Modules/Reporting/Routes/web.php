<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\ConsolidationController;
use Modules\Reporting\Http\Controllers\FileController;
use Modules\Reporting\Http\Controllers\FollowUpController;
use Modules\Reporting\Http\Controllers\ObligationController;
use Modules\Reporting\Http\Controllers\OverviewController;
use Modules\Reporting\Http\Controllers\PrepareController;
use Modules\Reporting\Http\Controllers\RegisterController;
use Modules\Reporting\Http\Controllers\ReportTypeController;
use Modules\Reporting\Http\Controllers\ReviewController;
use Modules\Reporting\Http\Controllers\ScheduleController;
use Modules\Reporting\Http\Controllers\SettingsController;

/*
|--------------------------------------------------------------------------
| Institutional Reporting & Compliance — Modules/Reporting/README.md
|--------------------------------------------------------------------------
| Staff pages, loaded by the module's provider inside the "web" group.
| Route middleware authenticates; every action then resolves the actor's
| security context and is authorized in its use case (permission AND
| organization scope). The feature flag "reporting" (config/features.php,
| route prefix "reporting.") turns every route into a 404; the module's own
| maintenance switch refuses writes while keeping the pages readable.
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('reporting')->name('reporting.')
    ->group(function (): void {
        Route::get('/', OverviewController::class)->name('overview');
        Route::get('/assignments', [PrepareController::class, 'assignments'])->name('assignments');
        Route::get('/calendar', [RegisterController::class, 'calendar'])->name('calendar');
        Route::get('/register', [RegisterController::class, 'index'])->name('register');
        Route::get('/register/export', [RegisterController::class, 'export'])->middleware('throttle:10,1')->name('register.export');
        Route::get('/archive', [RegisterController::class, 'archive'])->name('archive');

        // One report (obligation) and the decisions about it.
        Route::get('/obligations/{obligation}', [ObligationController::class, 'show'])->whereUuid('obligation')->name('obligations.show');
        Route::post('/obligations/{obligation}/extensions', [ObligationController::class, 'requestExtension'])->whereUuid('obligation')->name('extensions.store');
        Route::post('/extensions/{extension}/decision', [ObligationController::class, 'decideExtension'])->whereUuid('extension')->name('extensions.decide');
        Route::post('/obligations/{obligation}/applicability', [ObligationController::class, 'applicability'])->whereUuid('obligation')->name('obligations.applicability');
        Route::post('/obligations/{obligation}/responsible', [ObligationController::class, 'responsible'])->whereUuid('obligation')->name('obligations.responsible');
        Route::post('/obligations/{obligation}/comments', [ObligationController::class, 'comment'])->whereUuid('obligation')->middleware('throttle:60,1')->name('obligations.comments');
        Route::post('/obligations/{obligation}/reopen', [ObligationController::class, 'reopen'])->whereUuid('obligation')->name('obligations.reopen');

        // Preparing and submitting.
        Route::get('/obligations/{obligation}/prepare', [PrepareController::class, 'edit'])->whereUuid('obligation')->name('prepare.edit');
        Route::put('/obligations/{obligation}/draft', [PrepareController::class, 'save'])->whereUuid('obligation')->middleware('throttle:240,1')->name('prepare.save');
        Route::post('/obligations/{obligation}/files', [PrepareController::class, 'upload'])->whereUuid('obligation')->middleware('throttle:60,1')->name('prepare.upload');
        Route::delete('/files/{attachment}', [PrepareController::class, 'remove'])->whereUuid('attachment')->name('prepare.remove');
        Route::post('/obligations/{obligation}/submit', [PrepareController::class, 'submit'])->whereUuid('obligation')->middleware('throttle:30,1')->name('prepare.submit');
        Route::get('/obligations/{obligation}/template', [PrepareController::class, 'template'])->whereUuid('obligation')->middleware('throttle:30,1')->name('prepare.template');
        Route::get('/obligations/{obligation}/draft-document', [PrepareController::class, 'draftDocument'])->whereUuid('obligation')->middleware('throttle:30,1')->name('prepare.draft-document');

        // Files: private storage, authorized and audited at each download.
        Route::get('/files/{attachment}', [FileController::class, 'attachment'])->whereUuid('attachment')->name('files.attachment');
        Route::get('/generated/{artifact}', [FileController::class, 'artifact'])->whereUuid('artifact')->name('files.artifact');
        Route::get('/follow-up-updates/{update}/evidence', [FileController::class, 'evidence'])->whereUuid('update')->name('files.evidence');
        Route::get('/lookups/organizations', [FileController::class, 'organizations'])->middleware('throttle:120,1')->name('lookups.organizations');
        Route::get('/lookups/units', [FileController::class, 'units'])->middleware('throttle:120,1')->name('lookups.units');
        Route::get('/lookups/users', [FileController::class, 'users'])->middleware('throttle:120,1')->name('lookups.users');

        // Review.
        Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
        Route::get('/review/{submission}', [ReviewController::class, 'show'])->whereUuid('submission')->name('review.show');
        Route::post('/review/{submission}/decision', [ReviewController::class, 'decide'])->whereUuid('submission')->name('review.decide');
        Route::post('/review-steps/{step}/reassign', [ReviewController::class, 'reassign'])->whereUuid('step')->name('review.reassign');

        // Follow-up actions.
        Route::get('/follow-ups', [FollowUpController::class, 'index'])->name('follow-ups.index');
        Route::post('/obligations/{obligation}/follow-ups', [FollowUpController::class, 'store'])->whereUuid('obligation')->name('follow-ups.store');
        Route::post('/follow-ups/{followUp}', [FollowUpController::class, 'update'])->whereUuid('followUp')->name('follow-ups.update');

        // Consolidated reports.
        Route::get('/consolidations', [ConsolidationController::class, 'index'])->name('consolidations.index');
        Route::post('/consolidations', [ConsolidationController::class, 'store'])->name('consolidations.store');
        Route::get('/consolidations/{consolidation}', [ConsolidationController::class, 'show'])->whereUuid('consolidation')->name('consolidations.show');
        Route::put('/consolidations/{consolidation}', [ConsolidationController::class, 'update'])->whereUuid('consolidation')->name('consolidations.update');
        Route::post('/consolidations/{consolidation}/compile', [ConsolidationController::class, 'compile'])->whereUuid('consolidation')->name('consolidations.compile');

        // Report types and templates.
        Route::get('/types', [ReportTypeController::class, 'index'])->name('types.index');
        Route::get('/types/create', [ReportTypeController::class, 'create'])->name('types.create');
        Route::post('/types', [ReportTypeController::class, 'store'])->name('types.store');
        Route::get('/types/{type}/edit', [ReportTypeController::class, 'edit'])->whereUuid('type')->name('types.edit');
        Route::put('/types/{type}', [ReportTypeController::class, 'update'])->whereUuid('type')->name('types.update');
        Route::put('/types/{type}/template', [ReportTypeController::class, 'saveTemplate'])->whereUuid('type')->name('types.template.save');
        Route::post('/types/{type}/template/publish', [ReportTypeController::class, 'publishTemplate'])->whereUuid('type')->name('types.template.publish');
        Route::get('/types/{type}/template-document', [ReportTypeController::class, 'templateDocument'])->whereUuid('type')->middleware('throttle:30,1')->name('types.template.document');

        // Schedules, periods and special reports.
        Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::get('/schedules/create', [ScheduleController::class, 'create'])->name('schedules.create');
        Route::post('/schedules', [ScheduleController::class, 'store'])->name('schedules.store');
        Route::post('/schedules/preview', [ScheduleController::class, 'preview'])->middleware('throttle:120,1')->name('schedules.preview');
        Route::post('/special-reports', [ScheduleController::class, 'special'])->name('schedules.special');
        Route::get('/schedules/{schedule}/edit', [ScheduleController::class, 'edit'])->whereUuid('schedule')->name('schedules.edit');
        Route::put('/schedules/{schedule}', [ScheduleController::class, 'update'])->whereUuid('schedule')->name('schedules.update');
        Route::post('/schedules/{schedule}/publish', [ScheduleController::class, 'publish'])->whereUuid('schedule')->name('schedules.publish');

        // Settings and operational health.
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });
