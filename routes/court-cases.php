<?php

declare(strict_types=1);

use App\Http\Controllers\CourtCases\CourtCaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Court Cases — planned standalone module (docs/court-cases.md)
|--------------------------------------------------------------------------
| Only the module entry exists. It is a separate domain from Grievance
| Management: a grievance is never converted into a court case, and any
| future link will be an explicit referral. Staff area only, behind MFA;
| there is no public or API route.
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('court-cases')->name('court-cases.')
    ->group(function (): void {
        Route::get('/', [CourtCaseController::class, 'index'])->name('index');
    });
