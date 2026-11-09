<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Load-balancer probes. Registered without the web middleware group, so a
| probe every few seconds never starts a session (a database session row)
| or touches CSRF/locale handling. GET /up (Laravel default) remains.
*/
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
