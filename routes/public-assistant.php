<?php

declare(strict_types=1);

use App\Http\Controllers\Public\PublicAssistantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Addis One Help — public-site assistant (docs/public-assistant/architecture.md)
|--------------------------------------------------------------------------
|
| Anonymous JSON endpoints: the public-site switch, the `public-assistant`
| limiter (IP + session) and no-store headers. No authentication, no
| session user is ever read.
|
*/

Route::middleware(['public.site', 'throttle:public-assistant'])
    ->prefix('public-assistant')->name('public-assistant.')
    ->group(function (): void {
        Route::get('/bootstrap', [PublicAssistantController::class, 'bootstrap'])->name('bootstrap');
        Route::post('/messages', [PublicAssistantController::class, 'message'])->name('messages.store');
        Route::post('/reset', [PublicAssistantController::class, 'reset'])->name('reset');
    });
