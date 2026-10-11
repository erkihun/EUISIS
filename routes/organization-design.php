<?php

declare(strict_types=1);

use App\Http\Controllers\OrganizationDesign\ClassificationController;
use App\Http\Controllers\OrganizationDesign\FunctionController;
use App\Http\Controllers\OrganizationDesign\MandateController;
use App\Http\Controllers\OrganizationDesign\ServiceController;
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

        // Phase 2: service catalog and classifications.
        Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
        Route::post('/services', [ServiceController::class, 'store'])->middleware('throttle:60,1')->name('services.store');
        Route::get('/services/{service}', [ServiceController::class, 'show'])->whereUuid('service')->name('services.show');
        Route::put('/services/{service}', [ServiceController::class, 'update'])->whereUuid('service')->name('services.update');
        Route::post('/services/{service}/versions', [ServiceController::class, 'storeVersion'])->whereUuid('service')->name('services.versions.store');
        Route::post('/services/{service}/publish', [ServiceController::class, 'publish'])->whereUuid('service')->name('services.publish');
        Route::post('/services/{service}/cancel', [ServiceController::class, 'cancel'])->whereUuid('service')->name('services.cancel');

        Route::get('/classifications', [ClassificationController::class, 'index'])->name('classifications.index');
        Route::post('/classifications', [ClassificationController::class, 'store'])->name('classifications.store');
        Route::put('/classifications/{classification}', [ClassificationController::class, 'update'])->whereUuid('classification')->name('classifications.update');
        Route::post('/classifications/import', [ClassificationController::class, 'import'])->middleware('throttle:10,1')->name('classifications.import');
    });
