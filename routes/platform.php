<?php

declare(strict_types=1);

use App\Http\Controllers\Web\EmployeeLookupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform routes shared across modules
|--------------------------------------------------------------------------
| Cross-cutting endpoints used by many admin pages (scoped lookups). Kept out
| of routes/web.php so production-hardening changes stay reviewable.
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])->group(function (): void {
    // Scoped employee search for admin pickers — never a full employee list.
    Route::get('/lookup/employees', EmployeeLookupController::class)
        ->middleware('throttle:120,1')
        ->name('lookup.employees');
});
