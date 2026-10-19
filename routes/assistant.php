<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Assistant\AssistantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Addis One Assistant (docs/assistant/architecture.md)
|--------------------------------------------------------------------------
|
| JSON endpoints behind the authenticated session. No grant is needed to
| talk to the assistant; every tool checks its own grant and scope in the
| resolver. Message endpoints carry the `assistant` named limiter.
|
*/

Route::middleware(['auth', 'force.password', 'admin.access'])
    ->prefix('assistant')->name('assistant.')
    ->group(function (): void {
        Route::get('/bootstrap', [AssistantController::class, 'bootstrap'])->name('bootstrap');
        Route::get('/conversations', [AssistantController::class, 'conversations'])->name('conversations.index');
        Route::post('/conversations', [AssistantController::class, 'startConversation'])->middleware('throttle:assistant')->name('conversations.store');
        Route::get('/conversations/{conversation}', [AssistantController::class, 'conversation'])->whereUuid('conversation')->name('conversations.show');
        Route::post('/messages', [AssistantController::class, 'message'])->middleware('throttle:assistant')->name('messages.store');
        Route::post('/messages/{message}/feedback', [AssistantController::class, 'feedback'])->whereUuid('message')->middleware('throttle:assistant')->name('messages.feedback');
        Route::post('/tickets', [AssistantController::class, 'ticket'])->middleware('throttle:assistant')->name('tickets.store');
    });
