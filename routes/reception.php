<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reception\Http\Controllers\ReceptionOperationController;
use Modules\Reception\Http\Controllers\ReceptionPrintController;
use Modules\Reception\Http\Controllers\ReceptionReportController;
use Modules\Reception\Http\Controllers\ReceptionWorkspaceController;

Route::prefix('reception')->name('reception.')->group(function (): void {
    foreach ([
        'dashboard' => '', 'register' => '/visitors/register', 'check-in' => '/check-in', 'today' => '/visitors/today',
        'history' => '/visitors/history', 'queue' => '/queue', 'counters' => '/counters', 'appointments' => '/appointments',
        'directory' => '/service-directory', 'referrals' => '/referrals', 'desks' => '/desks', 'officers' => '/officers',
        'reports' => '/reports', 'settings' => '/settings',
    ] as $name => $uri) {
        Route::get($uri, ReceptionWorkspaceController::class)->defaults('section', $name)->name($name);
    }

    Route::post('/desks', [ReceptionOperationController::class, 'desk'])->name('desks.store')->middleware('throttle:60,1');
    Route::post('/counters', [ReceptionOperationController::class, 'counter'])->name('counters.store')->middleware('throttle:120,1');
    Route::patch('/counters/{counter}', [ReceptionOperationController::class, 'updateCounter'])->name('counters.update')->middleware('throttle:120,1');
    Route::post('/staff', [ReceptionOperationController::class, 'staff'])->name('staff.store')->middleware('throttle:60,1');
    Route::post('/visits', [ReceptionOperationController::class, 'visit'])->name('visits.store')->middleware('throttle:120,1');
    Route::post('/visits/{visit}/queue-ticket', [ReceptionOperationController::class, 'ticket'])->name('tickets.store')->middleware('throttle:120,1');
    Route::patch('/queue-tickets/{ticket}', [ReceptionOperationController::class, 'transition'])->name('tickets.transition')->middleware('throttle:240,1');
    Route::post('/appointments', [ReceptionOperationController::class, 'appointment'])->name('appointments.store')->middleware('throttle:60,1');
    Route::patch('/appointments/{appointment}', [ReceptionOperationController::class, 'updateAppointment'])->name('appointments.update')->middleware('throttle:60,1');
    Route::post('/visits/{visit}/referrals', [ReceptionOperationController::class, 'referral'])->name('referrals.store')->middleware('throttle:60,1');
    Route::patch('/referrals/{referral}/acknowledge', [ReceptionOperationController::class, 'acknowledgeReferral'])->name('referrals.acknowledge')->middleware('throttle:60,1');
    Route::get('/reports/export.csv', ReceptionReportController::class)->name('reports.export')->middleware('throttle:20,1');
    Route::get('/queue-tickets/{ticket}/print', [ReceptionPrintController::class, 'ticket'])->name('tickets.print');
    Route::get('/appointments/{appointment}/print', [ReceptionPrintController::class, 'appointment'])->name('appointments.print');
    Route::get('/referrals/{referral}/print', [ReceptionPrintController::class, 'referral'])->name('referrals.print');
});
