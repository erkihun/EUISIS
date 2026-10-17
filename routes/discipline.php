<?php

declare(strict_types=1);

use App\Http\Controllers\Discipline\DisciplinaryCaseController;
use App\Http\Controllers\Discipline\DisciplinaryPortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Employee Discipline (docs/employee-discipline.md)
|--------------------------------------------------------------------------
| A separate domain from Employee Grievance and from public service
| complaints. Authorization is enforced in DisciplinaryCaseService /
| DisciplinaryCaseAccess; routes only name things.
*/

// ── My Portal → My Disciplinary Cases (the accused employee) ────────────────
Route::middleware(['auth', 'force.password', 'admin.access'])
    ->prefix('my-portal/discipline')->name('employee.discipline.')
    ->whereUuid(['case', 'evidence'])
    ->controller(DisciplinaryPortalController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/{case}', 'show')->name('show');
        Route::post('/{case}/response', 'respond')->middleware('throttle:20,1')->name('response');
        Route::post('/{case}/evidence', 'evidence')->middleware('throttle:20,1')->name('evidence.store');
        Route::get('/{case}/evidence/{evidence}', 'downloadEvidence')->name('evidence.download');
        Route::post('/{case}/final-statement', 'finalStatement')->middleware('throttle:20,1')->name('final-statement');
    });

// ── Staff area ───────────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('discipline/cases')->name('discipline.cases.')
    ->whereUuid(['case', 'evidence', 'member'])
    ->controller(DisciplinaryCaseController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
        Route::get('/{case}', 'show')->name('show');
        Route::post('/{case}/charges', 'addCharge')->name('charges.store');
        Route::post('/{case}/panel', 'assignPanel')->name('panel');
        Route::post('/{case}/file', 'fileCharge')->name('file');
        Route::post('/{case}/summons', 'serveSummons')->name('summons');
        Route::post('/{case}/response', 'recordResponse')->name('response');
        Route::post('/{case}/objection', 'decideObjection')->name('objection');
        Route::post('/{case}/in-absentia', 'proceedInAbsentia')->name('absentia');
        Route::post('/{case}/evidence', 'addEvidence')->middleware('throttle:30,1')->name('evidence.store');
        Route::get('/{case}/evidence/{evidence}', 'downloadEvidence')->name('evidence.download');
        Route::post('/{case}/witnesses', 'recordWitness')->name('witnesses.store');
        Route::post('/{case}/members/{member}/recuse', 'recuse')->name('members.recuse');
        Route::post('/{case}/final-statement', 'finalStatement')->name('final-statement');
        Route::post('/{case}/extend', 'extend')->name('extend');
        Route::post('/{case}/recommendation', 'recommend')->name('recommendation');
        Route::post('/{case}/decision', 'decide')->name('decision');
        Route::post('/{case}/delivery', 'deliver')->name('delivery');
        // Regulation 201/2018 Art. 12: suspension pending the case.
        Route::post('/{case}/suspension', 'suspend')->name('suspension.store');
        Route::post('/{case}/suspension/lift', 'liftSuspension')->name('suspension.lift');
        Route::post('/{case}/suspension/back-pay', 'suspensionBackPay')->name('suspension.back-pay');
        // Regulation 201/2018 Part Five: the employee's appeal to the Administrative Court.
        Route::post('/{case}/appeal', 'appealNotice')->name('appeal.notice');
        Route::post('/{case}/appeal/record-copy', 'appealRecordCopy')->name('appeal.record-copy');
        Route::post('/{case}/appeal/filing', 'appealFiling')->name('appeal.filing');
        Route::post('/{case}/appeal/outcome', 'appealOutcome')->name('appeal.outcome');
        Route::post('/{case}/appeal/implemented', 'appealImplemented')->name('appeal.implemented');
        Route::post('/{case}/discontinue', 'discontinue')->name('discontinue');
        Route::post('/{case}/terminate', 'terminate')->name('terminate');
        Route::post('/{case}/close', 'close')->name('close');
    });
