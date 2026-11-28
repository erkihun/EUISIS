<?php

declare(strict_types=1);

use App\Http\Controllers\PilotWeekDashboardController;
use Illuminate\Support\Facades\Route;

/*
| The temporary pilot week dashboard. Registered outside the web middleware
| group like the health probes: no session, no sign-in, no locale handling.
| The address carries a secret token (php artisan pilot:dashboard-link) and
| nothing in the application or on the public site links to it.
*/
Route::get('/week-progress/{token}', PilotWeekDashboardController::class)
    ->where('token', '[A-Za-z0-9_-]{8,64}')
    ->middleware('throttle:60,1')
    ->name('pilot.week-dashboard');
