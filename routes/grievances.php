<?php

declare(strict_types=1);

use App\Http\Controllers\Grievances\GrievanceApprovalController;
use App\Http\Controllers\Grievances\GrievanceCaseController;
use App\Http\Controllers\Grievances\GrievanceCommitteeController;
use App\Http\Controllers\Grievances\GrievanceCorrespondenceController;
use App\Http\Controllers\Grievances\GrievanceDecisionController;
use App\Http\Controllers\Grievances\GrievanceEvidenceController;
use App\Http\Controllers\Grievances\GrievanceLookupController;
use App\Http\Controllers\Grievances\GrievancePortalController;
use App\Http\Controllers\Grievances\GrievanceProceedingsController;
use App\Http\Controllers\Grievances\GrievanceReportController;
use App\Http\Controllers\Grievances\GrievanceRoutingController;
use App\Http\Controllers\Grievances\GrievanceSettingsController;
use App\Http\Controllers\Web\AdministrativeTribunalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Grievance Management (docs/grievance-management.md)
|--------------------------------------------------------------------------
| Employee self-service lives under My Portal with the portal middleware.
| Staff work lives under /grievances behind MFA. Authorization is enforced
| in the services (GrievanceCaseAccessService); routes only name things.
*/

// ── My Portal → My Grievances ────────────────────────────────────────────────
Route::middleware(['auth', 'force.password', 'admin.access'])
    ->prefix('my-portal/grievances')->name('employee.grievances.')
    ->whereUuid(['grievance', 'letter', 'evidence', 'informationRequest'])
    ->controller(GrievancePortalController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
        Route::get('/{grievance}', 'show')->name('show');
        Route::get('/{grievance}/edit', 'edit')->name('edit');
        // POST (not PUT): the form may carry files.
        Route::post('/{grievance}/update', 'update')->middleware('throttle:20,1')->name('update');
        Route::post('/{grievance}/submit', 'submit')->name('submit');
        Route::delete('/{grievance}', 'destroy')->name('destroy');
        Route::post('/{grievance}/withdraw', 'withdraw')->name('withdraw');
        Route::post('/{grievance}/accept-outcome', 'acceptOutcome')->name('accept-outcome');
        Route::post('/{grievance}/evidence', 'uploadEvidence')->middleware('throttle:30,1')->name('evidence.store');
        Route::get('/{grievance}/evidence/{evidence}', 'downloadEvidence')->name('evidence.download');
        Route::post('/{grievance}/information-requests/{informationRequest}/respond', 'respond')->middleware('throttle:20,1')->name('information.respond');
        Route::post('/{grievance}/appeal', 'appeal')->middleware('throttle:10,1')->name('appeal');
        Route::get('/{grievance}/letters/{letter}', 'downloadLetter')->name('letters.download');
    });

// ── Staff area ───────────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])->group(function (): void {
    // Old links from the first grievance module.
    Route::redirect('/grievances/my', '/my-portal/grievances');
    Route::redirect('/grievances/create', '/my-portal/grievances/create');

    Route::prefix('grievances')->name('grievances.')
        ->whereUuid(['grievance', 'evidence', 'informationRequest', 'hearing', 'minutes', 'decision', 'letter', 'dispatch', 'officer', 'task', 'pause', 'recusal', 'action', 'committee', 'member', 'grievanceRoute', 'profile', 'category', 'reasonCode', 'rule', 'authority', 'template', 'seal', 'delegation'])
        ->group(function (): void {
            Route::get('/', fn () => to_route('grievances.dashboard'))->name('home');
            Route::get('/dashboard', [GrievanceCaseController::class, 'dashboard'])->name('dashboard');
            Route::get('/approvals', [GrievanceApprovalController::class, 'index'])->name('approvals.index');
            Route::get('/appeals', [GrievanceReportController::class, 'appeals'])->name('appeals.index');
            Route::get('/correspondence', [GrievanceCorrespondenceController::class, 'index'])->name('correspondence.index');
            Route::get('/reports', [GrievanceReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/export', [GrievanceReportController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');

            // Cases
            Route::prefix('cases')->name('cases.')->group(function (): void {
                Route::get('/', [GrievanceCaseController::class, 'index'])->name('index');
                Route::get('/{grievance}', [GrievanceCaseController::class, 'show'])->name('show');

                Route::controller(GrievanceCaseController::class)->group(function (): void {
                    Route::post('/{grievance}/intake', 'intake')->name('intake');
                    Route::post('/{grievance}/receive', 'receive')->name('receive');
                    Route::post('/{grievance}/start-review', 'startReview')->name('start-review');
                    Route::post('/{grievance}/classify', 'classify')->name('classify');
                    Route::post('/{grievance}/move', 'move')->name('move');
                    Route::post('/{grievance}/officers', 'assignOfficer')->name('officers.store');
                    Route::delete('/{grievance}/officers/{officer}', 'releaseOfficer')->name('officers.destroy');
                    Route::post('/{grievance}/notes', 'storeNote')->name('notes.store');
                    Route::post('/{grievance}/tasks', 'storeTask')->name('tasks.store');
                    Route::patch('/{grievance}/tasks/{task}', 'updateTask')->name('tasks.update');
                    Route::post('/{grievance}/pauses', 'requestPause')->name('pauses.store');
                    Route::post('/{grievance}/pauses/{pause}', 'decidePause')->name('pauses.decide');
                    Route::post('/{grievance}/recusals', 'declareRecusal')->name('recusals.store');
                    Route::post('/{grievance}/recusals/{recusal}', 'decideRecusal')->name('recusals.decide');
                    Route::post('/{grievance}/withdrawal', 'decideWithdrawal')->name('withdrawal.decide');
                    Route::post('/{grievance}/close', 'close')->name('close');
                    Route::post('/{grievance}/reopen', 'reopen')->name('reopen');
                    Route::post('/{grievance}/archive', 'archive')->name('archive');
                    Route::post('/{grievance}/legal-hold', 'legalHold')->name('legal-hold');
                    Route::post('/{grievance}/corrective-actions', 'storeCorrectiveAction')->name('corrective-actions.store');
                    Route::patch('/{grievance}/corrective-actions/{action}', 'updateCorrectiveAction')->name('corrective-actions.update');
                    Route::post('/{grievance}/referrals', 'storeReferral')->name('referrals.store');
                });

                Route::controller(GrievanceEvidenceController::class)->group(function (): void {
                    Route::post('/{grievance}/evidence', 'store')->middleware('throttle:30,1')->name('evidence.store');
                    Route::post('/{grievance}/evidence/{evidence}/decide', 'decide')->name('evidence.decide');
                    Route::post('/{grievance}/evidence/{evidence}/classify', 'classify')->name('evidence.classify');
                    Route::post('/{grievance}/evidence/{evidence}/supersede', 'supersede')->middleware('throttle:30,1')->name('evidence.supersede');
                    Route::get('/{grievance}/evidence/{evidence}/custody', 'custody')->name('evidence.custody');
                    Route::get('/{grievance}/evidence/{evidence}/download', 'download')->name('evidence.download');
                });

                Route::controller(GrievanceProceedingsController::class)->group(function (): void {
                    Route::post('/{grievance}/information-requests', 'storeInformationRequest')->name('information.store');
                    Route::post('/{grievance}/information-requests/{informationRequest}/responses', 'recordResponse')->name('information.respond');
                    Route::post('/{grievance}/information-requests/{informationRequest}/close', 'closeInformationRequest')->name('information.close');
                    Route::post('/{grievance}/hearings', 'storeHearing')->name('hearings.store');
                    Route::patch('/{grievance}/hearings/{hearing}', 'updateHearing')->name('hearings.update');
                    Route::post('/{grievance}/minutes', 'storeMinutes')->name('minutes.store');
                    Route::patch('/{grievance}/minutes/{minutes}', 'updateMinutes')->name('minutes.update');
                });

                Route::controller(GrievanceDecisionController::class)->group(function (): void {
                    Route::post('/{grievance}/decisions', 'store')->name('decisions.store');
                    Route::patch('/{grievance}/decisions/{decision}', 'update')->name('decisions.update');
                    Route::post('/{grievance}/decisions/{decision}/revise', 'revise')->name('decisions.revise');
                    Route::post('/{grievance}/decisions/{decision}/transition', 'transition')->name('decisions.transition');
                    Route::post('/{grievance}/decisions/{decision}/votes', 'vote')->name('decisions.vote');
                });

                Route::controller(GrievanceCorrespondenceController::class)->group(function (): void {
                    Route::post('/{grievance}/letters', 'store')->name('letters.store');
                    Route::patch('/{grievance}/letters/{letter}', 'update')->name('letters.update');
                    Route::post('/{grievance}/letters/{letter}/transition', 'transition')->name('letters.transition');
                    Route::post('/{grievance}/letters/{letter}/dispatch', 'dispatchLetter')->name('letters.dispatch');
                    Route::post('/{grievance}/letters/{letter}/dispatches/{dispatch}/acknowledge', 'acknowledge')->name('letters.acknowledge');
                    Route::get('/{grievance}/letters/{letter}/preview', 'preview')->middleware('throttle:30,1')->name('letters.preview');
                    Route::get('/{grievance}/letters/{letter}/download', 'download')->name('letters.download');
                });
            });

            // Committees
            Route::controller(GrievanceCommitteeController::class)->prefix('committees')->name('committees.')->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/{committee}', 'show')->name('show');
                Route::patch('/{committee}', 'update')->name('update');
                Route::post('/{committee}/approve', 'approve')->name('approve');
                Route::post('/{committee}/members', 'addMember')->name('members.store');
                Route::post('/{committee}/members/{member}/end', 'endMember')->name('members.end');
            });

            // Routing graph and SLA policies
            Route::controller(GrievanceRoutingController::class)->group(function (): void {
                Route::get('/routes', 'routes')->name('routes.index');
                Route::post('/routes', 'storeRoute')->name('routes.store');
                Route::patch('/routes/{grievanceRoute}', 'updateRoute')->name('routes.update');
                Route::post('/routes/{grievanceRoute}/approve', 'approveRoute')->name('routes.approve');
                Route::get('/sla-policies', 'slaProfiles')->name('sla.index');
                Route::post('/sla-policies', 'storeSlaProfile')->name('sla.store');
                Route::patch('/sla-policies/{profile}', 'updateSlaProfile')->name('sla.update');
            });

            // Settings
            Route::controller(GrievanceSettingsController::class)->prefix('settings')->name('settings.')->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::put('/policy', 'updatePolicy')->name('policy.update');
                Route::post('/categories', 'saveCategory')->name('categories.store');
                Route::patch('/categories/{category}', 'saveCategory')->name('categories.update');
                Route::post('/reason-codes', 'saveReasonCode')->name('reason-codes.store');
                Route::patch('/reason-codes/{reasonCode}', 'saveReasonCode')->name('reason-codes.update');
                Route::post('/approval-rules', 'saveApprovalRule')->name('approval-rules.store');
                Route::patch('/approval-rules/{rule}', 'saveApprovalRule')->name('approval-rules.update');
                Route::post('/external-authorities', 'saveExternalAuthority')->name('external-authorities.store');
                Route::patch('/external-authorities/{authority}', 'saveExternalAuthority')->name('external-authorities.update');
                Route::post('/templates', 'saveTemplate')->name('templates.store');
                Route::patch('/templates/{template}', 'saveTemplate')->name('templates.update');
                Route::post('/letterheads', 'saveLetterhead')->name('letterheads.save');
                Route::post('/seals', 'uploadSeal')->middleware('throttle:10,1')->name('seals.store');
                Route::post('/seals/{seal}/decide', 'decideSeal')->name('seals.decide');
                Route::get('/seals/{seal}/image', 'previewSeal')->name('seals.image');
                Route::post('/delegations', 'saveDelegation')->name('delegations.store');
                Route::post('/delegations/{delegation}/revoke', 'revokeDelegation')->name('delegations.revoke');
            });

            // Lookups for pickers
            Route::controller(GrievanceLookupController::class)->prefix('lookup')->name('lookup.')->middleware('throttle:120,1')->group(function (): void {
                Route::get('/employees', 'employees')->name('employees');
                Route::get('/units', 'units')->name('units');
                Route::get('/positions', 'positions')->name('positions');
                Route::get('/users', 'users')->name('users');
            });

            // Old case links: /grievances/{id} → the case page.
            Route::get('/{grievance}', fn (string $grievance) => to_route('grievances.cases.show', $grievance));
        });

    // Administrative Tribunal register (external-authority stages keep it in step).
    Route::get('/tribunal-cases', [AdministrativeTribunalController::class, 'index'])->name('tribunal-cases.index');
    Route::get('/tribunal-cases/{administrativeTribunalCase}', [AdministrativeTribunalController::class, 'show'])->name('tribunal-cases.show');
    Route::patch('/tribunal-cases/{administrativeTribunalCase}', [AdministrativeTribunalController::class, 'update'])->name('tribunal-cases.update');
});
