<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Support\SupportTicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Support tickets (docs/assistant/support-helpdesk.md)
|--------------------------------------------------------------------------
|
| Every signed-in admin-side user may file a ticket and read their own; the
| policy (SupportTicketPolicy) decides who sees and works the rest. Writes
| are throttled; the assistant's own ticket endpoint lives in assistant.php.
|
*/

Route::middleware(['auth', 'verified', 'force.password', 'admin.access'])
    ->prefix('support')->name('support.')
    ->group(function (): void {
        Route::get('/tickets', [SupportTicketController::class, 'index'])->name('tickets.index');
        Route::post('/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:30,1')->name('tickets.store');
        Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show'])->whereUuid('ticket')->name('tickets.show');
        Route::patch('/tickets/{ticket}', [SupportTicketController::class, 'update'])->whereUuid('ticket')
            ->middleware('throttle:30,1')->name('tickets.update');
    });
