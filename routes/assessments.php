<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Assessment Management
|--------------------------------------------------------------------------
| Competency / behavioural assessments, a module of their own (sidebar group
| "Assessment Management"): forms, assessment records, and oversight &
| compliance. Route names are unchanged; only the URLs moved here from
| /performance/... — the old URLs redirect permanently (bookmarks, links in
| notifications already sent).
*/

foreach ([
    'performance/assessment-forms' => 'assessments/forms',
    'performance/assessment-oversight' => 'assessments/oversight',
    'performance/assessments' => 'assessments/records',
] as $old => $new) {
    Route::get($old.'/{rest?}', function (\Illuminate\Http\Request $request, ?string $rest = null) use ($new) {
        $query = $request->getQueryString();

        return redirect('/'.$new.($rest ? '/'.$rest : '').($query ? '?'.$query : ''), 301);
    })->where('rest', '.*');
}

/*
|--------------------------------------------------------------------------
| Assessment records (peer assessments and the institutional summary)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('assessments/records')->group(function (): void {
        $controller = \App\Http\Controllers\Web\AssessmentRecordController::class;
        Route::get('/', [$controller, 'index'])->name('assessment-records.index');
        Route::post('/', [$controller, 'store'])->name('assessment-records.store');
        Route::get('/{record}', [$controller, 'show'])->whereUuid('record')->name('assessment-records.show');
        Route::post('/{record}/submit', [$controller, 'submit'])->whereUuid('record')->name('assessment-records.submit');
        foreach (['review', 'acknowledge', 'unassessed'] as $action) {
            Route::post('/{record}/'.$action, [$controller, 'transition'])->whereUuid('record')->defaults('action', $action)->name('assessment-records.'.$action);
        }
    });

/*
|--------------------------------------------------------------------------
| Assessment Form Builder — docs/assessment-form-builder.md
|--------------------------------------------------------------------------
| Configurable competency / behavioural / leadership forms, built in the UI.
| Authorized in AssessmentFormPolicy (permission + organization scope).
*/
Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('assessments/forms')
    ->group(function (): void {
        $forms = \App\Http\Controllers\Web\AssessmentFormController::class;

        Route::get('/', [$forms, 'index'])->name('assessment-forms.index');
        Route::post('/', [$forms, 'store'])->name('assessment-forms.store');
        Route::get('/lookup', [$forms, 'lookup'])->name('assessment-forms.lookup');
        Route::get('/{form}', [$forms, 'show'])->whereUuid('form')->name('assessment-forms.show');
        Route::post('/{form}/versions', [$forms, 'newVersion'])->whereUuid('form')->name('assessment-forms.versions.store');
        Route::post('/{form}/clone', [$forms, 'cloneForm'])->whereUuid('form')->name('assessment-forms.clone');
        Route::post('/{form}/archive', [$forms, 'archive'])->whereUuid('form')->name('assessment-forms.archive');
        Route::get('/{form}/assignment-preview', [$forms, 'assignmentPreview'])->whereUuid('form')->name('assessment-forms.assignment-preview');

        Route::put('/versions/{version}', [$forms, 'saveDraft'])->whereUuid('version')->name('assessment-forms.versions.save');
        Route::post('/versions/{version}/validate', [$forms, 'validateDraft'])->whereUuid('version')->name('assessment-forms.versions.validate');
        Route::post('/versions/{version}/publish', [$forms, 'publish'])->whereUuid('version')->name('assessment-forms.versions.publish');
        Route::delete('/versions/{version}', [$forms, 'discardDraft'])->whereUuid('version')->name('assessment-forms.versions.discard');
        Route::get('/versions/{version}/preview', [$forms, 'preview'])->whereUuid('version')->name('assessment-forms.versions.preview');
    });

/*
|--------------------------------------------------------------------------
| Assessment Oversight & Compliance — docs/assessment-oversight.md
|--------------------------------------------------------------------------
| A separate governance area from the Form Builder. Every action re-checks
| permission + organization/unit scope in the controllers and services.
*/
Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('assessments/oversight')->name('assessment-oversight.')
    ->group(function (): void {
        $pages = \App\Http\Controllers\Web\AssessmentOversightController::class;
        $admin = \App\Http\Controllers\Web\AssessmentOversightAdminController::class;
        $export = \App\Http\Controllers\Web\AssessmentOversightExportController::class;

        Route::get('/', [$pages, 'dashboard'])->name('dashboard');
        Route::get('/institutions', [$pages, 'institutions'])->name('institutions');
        Route::get('/institutions/{organization}', [$pages, 'institution'])->whereUuid('organization')->name('institution');
        Route::get('/employees', [$pages, 'employees'])->name('employees');
        Route::get('/distribution', [$pages, 'distribution'])->name('distribution');
        Route::get('/gender', [$pages, 'gender'])->name('gender');
        Route::get('/data-quality', [$pages, 'dataQuality'])->name('data-quality');
        Route::get('/submissions', [$pages, 'submissionsIndex'])->name('submissions');
        Route::get('/reports', [$pages, 'reports'])->name('reports');
        Route::get('/export', [$export, 'export'])->name('export');

        Route::post('/cycles/{cycle}/submissions', [$admin, 'submit'])->whereUuid('cycle')->name('submissions.store');
        Route::post('/submissions/{submission}/{action}', [$admin, 'moveSubmission'])->whereUuid('submission')->whereIn('action', ['start-review', 'return', 'reject', 'verify', 'finalize'])->name('submissions.move');

        Route::get('/setup', [$admin, 'setup'])->name('setup');
        Route::post('/cycles', [$admin, 'storeCycle'])->name('cycles.store');
        Route::get('/cycles/{cycle}', [$admin, 'cycle'])->whereUuid('cycle')->name('cycles.show');
        Route::put('/cycles/{cycle}', [$admin, 'updateCycle'])->whereUuid('cycle')->name('cycles.update');
        Route::post('/cycles/{cycle}/status', [$admin, 'cycleStatus'])->whereUuid('cycle')->name('cycles.status');
        Route::post('/cycles/{cycle}/organizations', [$admin, 'addOrganizations'])->whereUuid('cycle')->name('cycles.organizations.store');
        Route::delete('/cycles/{cycle}/organizations/{organization}', [$admin, 'removeOrganization'])->whereUuid(['cycle', 'organization'])->name('cycles.organizations.destroy');
        Route::post('/cycles/{cycle}/eligibility/snapshot', [$admin, 'snapshot'])->whereUuid('cycle')->name('cycles.eligibility.snapshot');
        Route::post('/cycles/{cycle}/eligibility/finalize', [$admin, 'finalizeEligibility'])->whereUuid('cycle')->name('cycles.eligibility.finalize');
        Route::post('/cycles/{cycle}/assignments/generate', [$admin, 'generateAssignments'])->whereUuid('cycle')->name('cycles.assignments.generate');
        Route::post('/cycles/{cycle}/exclusions', [$admin, 'requestExclusion'])->whereUuid('cycle')->name('exclusions.store');
        Route::post('/exclusions/{exclusion}/decide', [$admin, 'decideExclusion'])->whereUuid('exclusion')->name('exclusions.decide');
        Route::post('/exclusions/{exclusion}/withdraw', [$admin, 'withdrawExclusion'])->whereUuid('exclusion')->name('exclusions.withdraw');
        Route::post('/eligibility/{eligibility}/restore', [$admin, 'restoreEligibility'])->whereUuid('eligibility')->name('eligibility.restore');

        Route::post('/band-policies', [$admin, 'storePolicy'])->name('band-policies.store');
        Route::put('/band-policies/{policy}', [$admin, 'savePolicy'])->whereUuid('policy')->name('band-policies.update');
        Route::post('/band-policies/{policy}/activate', [$admin, 'activatePolicy'])->whereUuid('policy')->name('band-policies.activate');
        Route::post('/band-policies/{policy}/versions', [$admin, 'newPolicyVersion'])->whereUuid('policy')->name('band-policies.versions');
        Route::post('/reasons', [$admin, 'storeReason'])->name('reasons.store');
        Route::put('/reasons/{reason}', [$admin, 'updateReason'])->whereUuid('reason')->name('reasons.update');
    });

/*
|--------------------------------------------------------------------------
| Assessment Execution — docs/assessment-execution.md
|--------------------------------------------------------------------------
| Evaluators (any employee with an evaluator assignment) work from their own
| assignments only; reviewers act within their organization scope. Every
| action re-checks permission + assignment + status (+ scope) in services.
*/
Route::middleware(['auth', 'force.password', 'admin.access'])
    ->prefix('assessments')->name('assessment-workspace.')
    ->whereUuid(['response', 'evidence'])
    ->group(function (): void {
        $workspace = \App\Http\Controllers\Web\AssessmentWorkspaceController::class;
        Route::get('/my-assigned', [$workspace, 'index'])->name('index');
        Route::get('/respond/{response}', [$workspace, 'show'])->name('show');
        Route::post('/respond/{response}/draft', [$workspace, 'draft'])->middleware('throttle:120,1')->name('draft');
        Route::post('/respond/{response}/submit', [$workspace, 'submit'])->name('submit');
        Route::post('/respond/{response}/conflict', [$workspace, 'conflict'])->name('conflict');
        Route::post('/respond/{response}/evidence', [$workspace, 'uploadEvidence'])->middleware('throttle:30,1')->name('evidence.store');
        Route::get('/evidence/{evidence}', [$workspace, 'downloadEvidence'])->name('evidence.download');
        Route::delete('/evidence/{evidence}', [$workspace, 'removeEvidence'])->name('evidence.destroy');
    });

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('assessments/reviews')->name('assessment-reviews.')
    ->whereUuid(['record', 'response'])
    ->group(function (): void {
        $reviews = \App\Http\Controllers\Web\AssessmentReviewController::class;
        Route::get('/', [$reviews, 'index'])->name('index');
        Route::get('/{record}', [$reviews, 'show'])->name('show');
        Route::post('/{record}/finalize', [$reviews, 'finalize'])->name('finalize');
        Route::post('/{record}/reopen', [$reviews, 'reopen'])->name('reopen');
        Route::post('/{record}/evaluators', [$reviews, 'assignEvaluators'])->name('evaluators.store');
        Route::post('/responses/{response}/return', [$reviews, 'returnResponse'])->name('responses.return');
        Route::post('/responses/{response}/reassign', [$reviews, 'reassign'])->name('responses.reassign');
        Route::post('/responses/{response}/reject-conflict', [$reviews, 'rejectConflict'])->name('responses.reject-conflict');
    });

// My Portal › My Assessments (own records only; no employee id in the URL).
Route::middleware(['auth', 'force.password', 'admin.access'])
    ->prefix('my-portal/assessments')->name('employee.assessments.')
    ->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Employee\MyAssessmentsController::class, 'index'])->name('index');
        Route::post('/{record}/acknowledge', [\App\Http\Controllers\Employee\MyAssessmentsController::class, 'acknowledge'])->whereUuid('record')->name('acknowledge');
    });
