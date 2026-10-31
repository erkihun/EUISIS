<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ServiceMonitoring\Http\Controllers\ActionItemController;
use Modules\ServiceMonitoring\Http\Controllers\AttachmentController;
use Modules\ServiceMonitoring\Http\Controllers\ClarificationController;
use Modules\ServiceMonitoring\Http\Controllers\ConsolidationController;
use Modules\ServiceMonitoring\Http\Controllers\DashboardController;
use Modules\ServiceMonitoring\Http\Controllers\ExportController;
use Modules\ServiceMonitoring\Http\Controllers\FormTemplateController;
use Modules\ServiceMonitoring\Http\Controllers\ParticipantController;
use Modules\ServiceMonitoring\Http\Controllers\PeriodController;
use Modules\ServiceMonitoring\Http\Controllers\ReportController;
use Modules\ServiceMonitoring\Http\Controllers\ReviewController;
use Modules\ServiceMonitoring\Http\Controllers\SectionController;
use Modules\ServiceMonitoring\Http\Controllers\SettingsController;
use Modules\ServiceMonitoring\Http\Controllers\SubmissionController;
use Modules\ServiceMonitoring\Http\Controllers\VerificationController;
use Modules\ServiceMonitoring\Http\Controllers\VisitController;

/*
|--------------------------------------------------------------------------
| Service Delivery Monitoring & Support — docs/service-monitoring.md
|--------------------------------------------------------------------------
| Staff pages. Route middleware authenticates; every action re-checks its
| permission and the record through the module's policies (scope included).
| Child rows are bound through their submission (scopeBindings), so a row of
| another submission answers 404. The feature flag (config/features.php,
| route prefix "service-monitoring.") turns every route into a 404.
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('service-monitoring')->name('service-monitoring.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        // Fiscal years and periods
        Route::get('/periods', [PeriodController::class, 'index'])->name('periods.index');
        Route::post('/fiscal-years', [PeriodController::class, 'storeFiscalYear'])->middleware('throttle:10,1')->name('fiscal-years.store');
        Route::get('/periods/{period}', [PeriodController::class, 'show'])->whereUuid('period')->name('periods.show');
        Route::put('/periods/{period}/schedule', [PeriodController::class, 'updateSchedule'])->whereUuid('period')->middleware('throttle:30,1')->name('periods.schedule');
        Route::post('/periods/{period}/lock', [PeriodController::class, 'lock'])->whereUuid('period')->middleware('throttle:10,1')->name('periods.lock');
        Route::post('/periods/{period}/reopen', [PeriodController::class, 'reopen'])->whereUuid('period')->middleware('throttle:10,1')->name('periods.reopen');
        Route::post('/periods/{period}/extensions', [PeriodController::class, 'grantExtension'])->whereUuid('period')->middleware('throttle:30,1')->name('periods.extensions.store');
        Route::post('/extensions/{extension}/revoke', [PeriodController::class, 'revokeExtension'])->whereUuid('extension')->middleware('throttle:30,1')->name('extensions.revoke');

        // Participant registry
        Route::get('/participants', [ParticipantController::class, 'index'])->name('participants.index');
        Route::post('/participants', [ParticipantController::class, 'store'])->middleware('throttle:60,1')->name('participants.store');
        Route::post('/participants/suggestions', [ParticipantController::class, 'applySuggestions'])->middleware('throttle:10,1')->name('participants.suggestions.apply');
        Route::put('/participants/{participant}', [ParticipantController::class, 'update'])->whereUuid('participant')->middleware('throttle:60,1')->name('participants.update');
        Route::put('/participants/{participant}/support', [ParticipantController::class, 'support'])->whereUuid('participant')->middleware('throttle:60,1')->name('participants.support');

        // Submissions and their sections
        Route::get('/submissions', [SubmissionController::class, 'index'])->name('submissions.index');
        Route::post('/periods/{period}/participants/{participant}/start', [SubmissionController::class, 'start'])->whereUuid(['period', 'participant'])->middleware('throttle:30,1')->name('submissions.start');
        Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->whereUuid('submission')->name('submissions.show');
        Route::get('/submissions/{submission}/edit', [SubmissionController::class, 'edit'])->whereUuid('submission')->name('submissions.edit');
        Route::get('/submissions/{submission}/employees', [SubmissionController::class, 'employees'])->whereUuid('submission')->middleware('throttle:60,1')->name('submissions.employees');
        Route::post('/submissions/{submission}/nothing', [SubmissionController::class, 'nothing'])->whereUuid('submission')->middleware('throttle:60,1')->name('submissions.nothing');
        Route::post('/submissions/{submission}/stamped-copy', [SubmissionController::class, 'stampedCopy'])->whereUuid('submission')->middleware('throttle:10,1')->name('submissions.stamped-copy');
        Route::post('/submissions/{submission}/submit', [SubmissionController::class, 'submit'])->whereUuid('submission')->middleware('throttle:10,1')->name('submissions.submit');

        Route::scopeBindings()->group(function (): void {
            Route::post('/submissions/{submission}/section-1', [SectionController::class, 'storeEntry'])->whereUuid('submission')->middleware('throttle:60,1')->name('submissions.section1.store');
            Route::put('/submissions/{submission}/section-1/{accountabilityEntry}', [SectionController::class, 'updateEntry'])->whereUuid(['submission', 'accountabilityEntry'])->middleware('throttle:60,1')->name('submissions.section1.update');
            Route::delete('/submissions/{submission}/section-1/{accountabilityEntry}', [SectionController::class, 'destroyEntry'])->whereUuid(['submission', 'accountabilityEntry'])->middleware('throttle:60,1')->name('submissions.section1.destroy');
            Route::post('/submissions/{submission}/section-1/suggestions', [SectionController::class, 'acceptSuggestion'])->whereUuid('submission')->middleware('throttle:60,1')->name('submissions.section1.suggestion');
            Route::put('/submissions/{submission}/section-2', [SectionController::class, 'section2'])->whereUuid('submission')->middleware('throttle:120,1')->name('submissions.section2');
            Route::post('/submissions/{submission}/section-2/prefill', [SectionController::class, 'prefill'])->whereUuid('submission')->middleware('throttle:10,1')->name('submissions.section2.prefill');
            Route::post('/submissions/{submission}/section-3', [SectionController::class, 'storeService'])->whereUuid('submission')->middleware('throttle:60,1')->name('submissions.section3.store');
            Route::put('/submissions/{submission}/section-3/{digitalService}', [SectionController::class, 'updateService'])->whereUuid(['submission', 'digitalService'])->middleware('throttle:60,1')->name('submissions.section3.update');
            Route::delete('/submissions/{submission}/section-3/{digitalService}', [SectionController::class, 'destroyService'])->whereUuid(['submission', 'digitalService'])->middleware('throttle:60,1')->name('submissions.section3.destroy');
            Route::put('/submissions/{submission}/section-4', [SectionController::class, 'section4'])->whereUuid('submission')->middleware('throttle:120,1')->name('submissions.section4');
            Route::put('/submissions/{submission}/custom', [SectionController::class, 'custom'])->whereUuid('submission')->middleware('throttle:120,1')->name('submissions.custom');
        });

        // Review, comments and corrections
        Route::get('/review', [ReviewController::class, 'queue'])->name('review.queue');
        Route::post('/submissions/{submission}/return', [ReviewController::class, 'returnSubmission'])->whereUuid('submission')->middleware('throttle:30,1')->name('review.return');
        Route::post('/submissions/{submission}/accept', [ReviewController::class, 'accept'])->whereUuid('submission')->middleware('throttle:30,1')->name('review.accept');
        Route::post('/submissions/{submission}/comments', [ReviewController::class, 'comment'])->whereUuid('submission')->middleware('throttle:60,1')->name('review.comments.store');
        Route::post('/submissions/{submission}/corrections', [ReviewController::class, 'requestCorrection'])->whereUuid('submission')->middleware('throttle:10,1')->name('corrections.store');
        Route::post('/corrections/{correction}/decide', [ReviewController::class, 'decideCorrection'])->whereUuid('correction')->middleware('throttle:30,1')->name('corrections.decide');

        // Consolidation, certification and the non-submission record
        Route::get('/consolidation/sub-city', [ConsolidationController::class, 'subCity'])->name('consolidation.sub-city');
        Route::get('/consolidation/city', [ConsolidationController::class, 'city'])->name('consolidation.city');
        Route::post('/periods/{period}/sub-cities/{participant}/certify', [ConsolidationController::class, 'certify'])->whereUuid(['period', 'participant'])->middleware('throttle:10,1')->name('consolidation.certify');
        Route::post('/periods/{period}/participants/{participant}/not-submitted', [ConsolidationController::class, 'notSubmitted'])->whereUuid(['period', 'participant'])->middleware('throttle:30,1')->name('consolidation.not-submitted');

        // Innovation & Technology Bureau verification
        Route::get('/verification', [VerificationController::class, 'index'])->name('verification.index');
        Route::post('/digital-services/{digitalService}/verify', [VerificationController::class, 'verify'])->whereUuid('digitalService')->middleware('throttle:120,1')->name('verification.verify');
        Route::post('/digital-services/{digitalService}/reject', [VerificationController::class, 'reject'])->whereUuid('digitalService')->middleware('throttle:60,1')->name('verification.reject');

        // Support: clarifications, visits, action items
        Route::get('/clarifications', [ClarificationController::class, 'index'])->name('clarifications.index');
        Route::post('/clarifications', [ClarificationController::class, 'store'])->middleware('throttle:20,1')->name('clarifications.store');
        Route::get('/clarifications/{clarification}', [ClarificationController::class, 'show'])->whereUuid('clarification')->name('clarifications.show');
        Route::post('/clarifications/{clarification}/messages', [ClarificationController::class, 'reply'])->whereUuid('clarification')->middleware('throttle:30,1')->name('clarifications.reply');
        Route::post('/clarifications/{clarification}/close', [ClarificationController::class, 'close'])->whereUuid('clarification')->middleware('throttle:30,1')->name('clarifications.close');

        Route::get('/visits', [VisitController::class, 'index'])->name('visits.index');
        Route::post('/visits', [VisitController::class, 'store'])->middleware('throttle:30,1')->name('visits.store');
        Route::get('/visits/{visit}', [VisitController::class, 'show'])->whereUuid('visit')->name('visits.show');
        Route::put('/visits/{visit}', [VisitController::class, 'update'])->whereUuid('visit')->middleware('throttle:30,1')->name('visits.update');
        Route::post('/visits/{visit}/findings', [VisitController::class, 'addFinding'])->whereUuid('visit')->middleware('throttle:30,1')->name('visits.findings.store');
        Route::post('/visits/{visit}/action-items', [VisitController::class, 'addActionItem'])->whereUuid('visit')->middleware('throttle:30,1')->name('visits.action-items.store');

        Route::get('/action-items', [ActionItemController::class, 'index'])->name('action-items.index');
        Route::put('/action-items/{actionItem}', [ActionItemController::class, 'update'])->whereUuid('actionItem')->middleware('throttle:30,1')->name('action-items.update');
        Route::post('/action-items/{actionItem}/evidence', [ActionItemController::class, 'evidence'])->whereUuid('actionItem')->middleware('throttle:10,1')->name('action-items.evidence');

        // Reports and exports
        Route::get('/reports', ReportController::class)->name('reports.index');
        Route::post('/exports', [ExportController::class, 'store'])->middleware('throttle:10,1')->name('exports.store');
        Route::get('/exports/{export}/download', [ExportController::class, 'download'])->whereUuid('export')->middleware('throttle:30,1')->name('exports.download');
        Route::get('/attachments/{attachment}/download', AttachmentController::class)->whereUuid('attachment')->middleware('throttle:30,1')->name('attachments.download');

        // Settings
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->middleware('throttle:20,1')->name('settings.update');

        // Form templates: the form builder (docs/service-monitoring.md §Form templates)
        Route::get('/forms', [FormTemplateController::class, 'index'])->name('forms.index');
        Route::get('/forms/create', [FormTemplateController::class, 'create'])->name('forms.create');
        Route::post('/forms', [FormTemplateController::class, 'store'])->middleware('throttle:30,1')->name('forms.store');
        Route::get('/forms/{template}', [FormTemplateController::class, 'show'])->whereUuid('template')->name('forms.show');
        Route::get('/forms/{template}/edit', [FormTemplateController::class, 'edit'])->whereUuid('template')->name('forms.edit');
        Route::put('/forms/{template}', [FormTemplateController::class, 'update'])->whereUuid('template')->middleware('throttle:60,1')->name('forms.update');
        Route::delete('/forms/{template}', [FormTemplateController::class, 'destroy'])->whereUuid('template')->middleware('throttle:30,1')->name('forms.destroy');
        Route::post('/forms/{template}/publish', [FormTemplateController::class, 'publish'])->whereUuid('template')->middleware('throttle:30,1')->name('forms.publish');
        Route::post('/forms/{template}/new-version', [FormTemplateController::class, 'newVersion'])->whereUuid('template')->middleware('throttle:30,1')->name('forms.new-version');
        Route::post('/forms/{template}/duplicate', [FormTemplateController::class, 'duplicate'])->whereUuid('template')->middleware('throttle:30,1')->name('forms.duplicate');
        Route::post('/forms/{template}/archive', [FormTemplateController::class, 'archive'])->whereUuid('template')->middleware('throttle:30,1')->name('forms.archive');
        Route::get('/forms/{template}/summary', [FormTemplateController::class, 'summary'])->whereUuid('template')->name('forms.summary');
    });
