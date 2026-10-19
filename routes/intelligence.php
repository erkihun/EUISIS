<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Intelligence\IntelligenceController;
use App\Services\Intelligence\IntelligenceArea;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Addis One Intelligence (docs/intelligence-architecture.md)
|--------------------------------------------------------------------------
|
| `intelligence.view` opens the launcher; every area checks its own grant in
| the controller through IntelligenceArea::visibleTo(), so a technical grant
| never opens a business area and the reverse.
|
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('intelligence')->name('intelligence.')
    ->group(function (): void {
        Route::get('/', [IntelligenceController::class, 'index'])->middleware('can:intelligence.view')->name('index');
        Route::get('/{area}', [IntelligenceController::class, 'area'])->middleware('can:intelligence.view')
            ->whereIn('area', array_column(IntelligenceArea::cases(), 'value'))->name('area');
    });
