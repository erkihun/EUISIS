<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Intelligence\IncidentController;
use App\Http\Controllers\Web\Intelligence\IntelligenceController;
use App\Http\Controllers\Web\Intelligence\RecommendationController;
use App\Http\Controllers\Web\Intelligence\RunbookController;
use App\Services\Intelligence\IntelligenceArea;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Addis One Intelligence (docs/intelligence-architecture.md)
|--------------------------------------------------------------------------
|
| `intelligence.view` opens the launcher; every area checks its own grant in
| the controller through IntelligenceArea::visibleTo(), so a technical grant
| never opens a business area and the reverse. Resource pages (incidents,
| recommendations, runbooks) come before the {area} wildcard.
|
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access', 'can:intelligence.view'])
    ->prefix('intelligence')->name('intelligence.')
    ->group(function (): void {
        Route::get('/', [IntelligenceController::class, 'index'])->name('index');

        Route::middleware('can:intelligence.incidents.view')->group(function (): void {
            Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
            Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->whereUuid('incident')->name('incidents.show');
            Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->whereUuid('incident')
                ->middleware(['can:intelligence.incidents.manage', 'throttle:30,1'])->name('incidents.update');
        });

        Route::middleware('can:intelligence.recommendations.view')->group(function (): void {
            Route::get('/recommendations', [RecommendationController::class, 'index'])->name('recommendations.index');
            Route::patch('/recommendations/{recommendation}', [RecommendationController::class, 'review'])->whereUuid('recommendation')
                ->middleware(['can:intelligence.recommendations.review', 'throttle:30,1'])->name('recommendations.review');
        });

        Route::middleware('can:intelligence.runbooks.view')->group(function (): void {
            Route::get('/runbooks', [RunbookController::class, 'index'])->name('runbooks.index');
            Route::get('/runbooks/{runbook}', [RunbookController::class, 'show'])->where('runbook', '[a-z_]+')->name('runbooks.show');
        });

        Route::get('/{area}', [IntelligenceController::class, 'area'])
            ->whereIn('area', array_column(IntelligenceArea::cases(), 'value'))->name('area');
    });
