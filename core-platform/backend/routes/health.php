<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Load-balancer and external-monitor probes (ADR 0012). Registered without the
| web middleware group, so a probe every few seconds never starts a session or
| touches CSRF/locale handling. GET /up (Laravel default) remains.
*/
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/health/monitor', [HealthController::class, 'monitor'])->middleware('throttle:120,1')->name('health.monitor');
