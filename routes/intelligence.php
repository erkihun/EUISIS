<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Intelligence\AlertController;
use App\Http\Controllers\Web\Intelligence\GovernanceController;
use App\Http\Controllers\Web\Intelligence\IncidentController;
use App\Http\Controllers\Web\Intelligence\IntelligenceController;
use App\Http\Controllers\Web\Intelligence\KnowledgeController;
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

        // Knowledge Assistant (KnowledgeController, docs/intelligence-knowledge.md).
        Route::middleware('can:intelligence.knowledge.view')->group(function (): void {
            Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
            Route::post('/knowledge/ask', [KnowledgeController::class, 'ask'])->middleware('throttle:20,1')->name('knowledge.ask');
        });
        Route::middleware('can:intelligence.governance.manage')->prefix('knowledge/documents')->name('knowledge.documents.')->group(function (): void {
            Route::get('/', [KnowledgeController::class, 'documents'])->name('index');
            Route::post('/', [KnowledgeController::class, 'store'])->middleware('throttle:30,1')->name('store');
            Route::patch('/{document}', [KnowledgeController::class, 'update'])->whereUuid('document')->middleware('throttle:30,1')->name('update');
            Route::delete('/{document}', [KnowledgeController::class, 'destroy'])->whereUuid('document')->middleware('throttle:30,1')->name('destroy');
        });

        // AI Governance & Audit (GovernanceController).
        Route::get('/governance', [GovernanceController::class, 'index'])->middleware('can:intelligence.governance.view')->name('governance.index');

        // Root-cause assistant: queues an AI explanation of an incident (diagnostics grant; AI may be off).
        Route::post('/incidents/{incident}/explain', [IncidentController::class, 'explain'])->whereUuid('incident')
            ->middleware(['can:intelligence.incidents.view', 'can:intelligence.diagnostics.view', 'throttle:10,1'])->name('incidents.explain');

        // Alerts (AlertController): business and technical alerts, acknowledged and resolved by people.
        Route::middleware('can:intelligence.view')->group(function (): void {
            Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
            Route::patch('/alerts/{alert}', [AlertController::class, 'update'])->whereUuid('alert')->middleware('throttle:30,1')->name('alerts.update');
        });

        // Parameter-less entry for the sidebar; the same page as /intelligence/system_health.
        Route::get('/system-health', [IntelligenceController::class, 'systemHealth'])
            ->middleware('can:intelligence.system-health.view')->name('system-health');

        Route::get('/{area}', [IntelligenceController::class, 'area'])
            ->whereIn('area', array_column(IntelligenceArea::cases(), 'value'))->name('area');
    });
