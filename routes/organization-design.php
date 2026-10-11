<?php

declare(strict_types=1);

use App\Http\Controllers\OrganizationDesign\FunctionController;
use App\Http\Controllers\OrganizationDesign\MandateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Organization Design & Workforce Planning
| (docs/organization-design-workforce-planning-plan.md)
|--------------------------------------------------------------------------
| Middleware authenticates; every action is authorized again by policy
| (permission) and OrganizationScopeService (organization scope).
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('organization-design')->name('organization-design.')
    ->group(function (): void {
        // Phase 1: mandates, powers, functions.
        Route::get('/mandates', [MandateController::class, 'index'])->name('mandates.index');
        Route::post('/mandates', [MandateController::class, 'store'])->middleware('throttle:60,1')->name('mandates.store');
        Route::get('/mandates/{mandate}', [MandateController::class, 'show'])->whereUuid('mandate')->name('mandates.show');
        Route::put('/mandates/{mandate}', [MandateController::class, 'update'])->whereUuid('mandate')->name('mandates.update');
        Route::post('/mandates/{mandate}/versions', [MandateController::class, 'storeVersion'])->whereUuid('mandate')->name('mandates.versions.store');
        Route::post('/mandates/{mandate}/publish', [MandateController::class, 'publish'])->whereUuid('mandate')->name('mandates.publish');
        Route::post('/mandates/{mandate}/cancel', [MandateController::class, 'cancel'])->whereUuid('mandate')->name('mandates.cancel');

        Route::post('/mandates/{mandate}/powers', [FunctionController::class, 'storePower'])->whereUuid('mandate')->name('mandates.powers.store');
        Route::put('/powers/{power}', [FunctionController::class, 'updatePower'])->whereUuid('power')->name('powers.update');
        Route::delete('/powers/{power}', [FunctionController::class, 'destroyPower'])->whereUuid('power')->name('powers.destroy');

        Route::get('/functions', [FunctionController::class, 'index'])->name('functions.index');
        Route::post('/mandates/{mandate}/functions', [FunctionController::class, 'store'])->whereUuid('mandate')->name('mandates.functions.store');
        Route::put('/functions/{function}', [FunctionController::class, 'update'])->whereUuid('function')->name('functions.update');
        Route::post('/functions/{function}/move', [FunctionController::class, 'move'])->whereUuid('function')->name('functions.move');
        Route::delete('/functions/{function}', [FunctionController::class, 'destroy'])->whereUuid('function')->name('functions.destroy');
    });
