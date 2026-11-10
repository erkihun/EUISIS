<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Letters\Http\Controllers\AttachmentController;
use Modules\Letters\Http\Controllers\ComposeLetterController;
use Modules\Letters\Http\Controllers\ExportController;
use Modules\Letters\Http\Controllers\LetterActionController;
use Modules\Letters\Http\Controllers\LetterController;
use Modules\Letters\Http\Controllers\LetterheadController;
use Modules\Letters\Http\Controllers\LetterPdfController;
use Modules\Letters\Http\Controllers\LettersDashboardController;
use Modules\Letters\Http\Controllers\LookupController;
use Modules\Letters\Http\Controllers\NumberingController;
use Modules\Letters\Http\Controllers\RegisterLetterController;
use Modules\Letters\Http\Controllers\RegistrationController;
use Modules\Letters\Http\Controllers\ReportController;
use Modules\Letters\Http\Controllers\RoutingController;
use Modules\Letters\Http\Controllers\StandardsController;
use Modules\Letters\Http\Controllers\TemplateController;
use Modules\Letters\Http\Controllers\VerificationController;

/*
|--------------------------------------------------------------------------
| Letters — ADR 0037, docs/letters.md
|--------------------------------------------------------------------------
| Staff pages. Route middleware authenticates; every action re-checks its
| permission and the record through the module's policies (scope included).
| The feature flag (config/features.php, route prefix "letters.") turns every
| route into a 404.
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('letters')->name('letters.')
    ->group(function (): void {
        Route::get('/', LettersDashboardController::class)->name('dashboard');
        Route::get('/registry', [LetterController::class, 'index'])->name('index');
        Route::get('/registry/export', ExportController::class)->middleware('throttle:10,1')->name('export');
        Route::get('/reports', ReportController::class)->name('reports');

        // Registering what arrived from outside; composing what goes out.
        Route::get('/register', [RegisterLetterController::class, 'create'])->name('register');
        Route::post('/register', [RegisterLetterController::class, 'store'])->middleware('throttle:60,1')->name('register.store');
        Route::get('/compose', [ComposeLetterController::class, 'create'])->name('compose');
        Route::post('/compose', [ComposeLetterController::class, 'store'])->middleware('throttle:60,1')->name('compose.store');
        Route::get('/lookups/{organization}', [LookupController::class, 'organization'])->whereUuid('organization')->middleware('throttle:120,1')->name('lookups.organization');

        // Templates and numbering (before /{letter} so their fixed segments win).
        Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
        Route::post('/templates', [TemplateController::class, 'store'])->middleware('throttle:60,1')->name('templates.store');
        Route::get('/templates/{template}', [TemplateController::class, 'show'])->whereUuid('template')->name('templates.show');
        Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->whereUuid('template')->name('templates.edit');
        Route::put('/templates/{template}', [TemplateController::class, 'update'])->whereUuid('template')->middleware('throttle:60,1')->name('templates.update');
        Route::post('/templates/{template}/publish', [TemplateController::class, 'publish'])->whereUuid('template')->middleware('throttle:30,1')->name('templates.publish');
        Route::post('/templates/{template}/archive', [TemplateController::class, 'archive'])->whereUuid('template')->middleware('throttle:30,1')->name('templates.archive');

        // Header and footer layouts: several per institution, each one composed (not fixed).
        Route::get('/letterheads', [LetterheadController::class, 'index'])->name('letterheads.index');
        Route::get('/letterheads/create', [LetterheadController::class, 'create'])->name('letterheads.create');
        Route::post('/letterheads', [LetterheadController::class, 'store'])->middleware('throttle:30,1')->name('letterheads.store');
        Route::get('/letterheads/{letterhead}/edit', [LetterheadController::class, 'edit'])->whereUuid('letterhead')->name('letterheads.edit');
        Route::put('/letterheads/{letterhead}', [LetterheadController::class, 'update'])->whereUuid('letterhead')->middleware('throttle:30,1')->name('letterheads.update');
        Route::post('/letterheads/{letterhead}', [LetterheadController::class, 'update'])->whereUuid('letterhead')->middleware('throttle:30,1')->name('letterheads.upload');
        Route::post('/letterheads/{letterhead}/duplicate', [LetterheadController::class, 'duplicate'])->whereUuid('letterhead')->middleware('throttle:30,1')->name('letterheads.duplicate');
        Route::post('/letterheads/{letterhead}/default', [LetterheadController::class, 'makeDefault'])->whereUuid('letterhead')->middleware('throttle:30,1')->name('letterheads.default');
        Route::delete('/letterheads/{letterhead}', [LetterheadController::class, 'destroy'])->whereUuid('letterhead')->middleware('throttle:30,1')->name('letterheads.destroy');
        Route::get('/letterheads/{letterhead}/preview', [LetterheadController::class, 'preview'])->whereUuid('letterhead')->middleware('throttle:30,1')->name('letterheads.preview');

        // Reply-time standards and the file classification scheme.
        Route::get('/standards', [StandardsController::class, 'edit'])->name('standards.edit');
        Route::put('/standards', [StandardsController::class, 'update'])->middleware('throttle:30,1')->name('standards.update');
        Route::post('/standards/file-classes', [StandardsController::class, 'storeClass'])->middleware('throttle:60,1')->name('file-classes.store');
        Route::put('/standards/file-classes/{fileClass}', [StandardsController::class, 'updateClass'])->whereUuid('fileClass')->middleware('throttle:60,1')->name('file-classes.update');

        Route::get('/numbering', [NumberingController::class, 'index'])->name('numbering.index');
        Route::put('/numbering', [NumberingController::class, 'save'])->middleware('throttle:30,1')->name('numbering.save');
        Route::post('/numbering/preview', [NumberingController::class, 'preview'])->middleware('throttle:120,1')->name('numbering.preview');

        // Registry entries and routing steps.
        Route::post('/registrations/{registration}/receive', [RegistrationController::class, 'receive'])->whereUuid('registration')->middleware('throttle:60,1')->name('registrations.receive');
        Route::post('/registrations/{registration}/close', [RegistrationController::class, 'close'])->whereUuid('registration')->middleware('throttle:60,1')->name('registrations.close');
        Route::post('/registrations/{registration}/acknowledge', [RegistrationController::class, 'acknowledge'])->whereUuid('registration')->middleware('throttle:60,1')->name('registrations.acknowledge');
        Route::post('/registrations/{registration}/routings', [RoutingController::class, 'store'])->whereUuid('registration')->middleware('throttle:60,1')->name('routings.store');
        Route::post('/routings/{routing}/acknowledge', [RoutingController::class, 'acknowledge'])->whereUuid('routing')->middleware('throttle:60,1')->name('routings.acknowledge');
        Route::post('/routings/{routing}/complete', [RoutingController::class, 'complete'])->whereUuid('routing')->middleware('throttle:60,1')->name('routings.complete');
        Route::post('/routings/{routing}/return', [RoutingController::class, 'return'])->whereUuid('routing')->middleware('throttle:60,1')->name('routings.return');
        Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->whereUuid('attachment')->middleware('throttle:120,1')->name('attachments.download');

        // The letter itself.
        Route::get('/{letter}', [LetterController::class, 'show'])->whereUuid('letter')->name('show');
        Route::get('/{letter}/edit', [ComposeLetterController::class, 'edit'])->whereUuid('letter')->name('edit');
        Route::put('/{letter}', [ComposeLetterController::class, 'update'])->whereUuid('letter')->middleware('throttle:60,1')->name('update');
        Route::get('/{letter}/pdf', LetterPdfController::class)->whereUuid('letter')->middleware('throttle:60,1')->name('pdf');
        Route::post('/{letter}/attachments', [AttachmentController::class, 'store'])->whereUuid('letter')->middleware('throttle:30,1')->name('attachments.store');
        Route::post('/{letter}/submit', [LetterActionController::class, 'submit'])->whereUuid('letter')->middleware('throttle:30,1')->name('submit');
        Route::post('/{letter}/approve', [LetterActionController::class, 'approve'])->whereUuid('letter')->middleware('throttle:30,1')->name('approve');
        Route::post('/{letter}/return', [LetterActionController::class, 'return'])->whereUuid('letter')->middleware('throttle:30,1')->name('return');
        Route::post('/{letter}/dispatch', [LetterActionController::class, 'dispatch'])->whereUuid('letter')->middleware('throttle:30,1')->name('dispatch');
        Route::post('/{letter}/cancel', [LetterActionController::class, 'cancel'])->whereUuid('letter')->middleware('throttle:30,1')->name('cancel');
        Route::post('/{letter}/reply', [LetterActionController::class, 'reply'])->whereUuid('letter')->middleware('throttle:30,1')->name('reply');
    });

// The public check of a printed letter (the QR code on the letterhead): no sign-in, no text, only who issued what and when.
Route::get('/verify/letter/{code}', VerificationController::class)->where('code', '[A-Za-z0-9 -]{4,20}')->middleware('throttle:60,1')->name('letters.verify');
