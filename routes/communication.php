<?php

declare(strict_types=1);

use App\Http\Controllers\Communication\AttachmentController;
use App\Http\Controllers\Communication\CommunicationController;
use App\Http\Controllers\Communication\ConversationController;
use App\Http\Controllers\Communication\DirectoryController;
use App\Http\Controllers\Communication\MessageController;
use App\Http\Controllers\Communication\MessagesController;
use App\Http\Middleware\EnsureCommunicationEnabled;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Communication & Collaboration (docs/communication-module.md)
|--------------------------------------------------------------------------
| Every route needs the module switched on and `communication.access`.
| JSON endpoints use the session (CSRF-protected); they are not an API.
| A conversation, message or file the user cannot see answers 404.
*/

Route::middleware(['auth', 'mfa', 'force.password', 'admin.access', EnsureCommunicationEnabled::class])
    ->prefix('communication')->name('communication.')
    ->group(function (): void {
        Route::get('/', [CommunicationController::class, 'index'])->name('index');
        Route::get('/summary', [CommunicationController::class, 'summary'])->middleware('throttle:60,1')->name('summary');

        Route::get('/messages', [MessagesController::class, 'index'])->name('messages.index');
        Route::get('/messages/{conversation}', [MessagesController::class, 'index'])->whereUuid('conversation')->name('messages.show');

        Route::prefix('api')->name('api.')->group(function (): void {
            Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
            Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:communication-start')->name('conversations.store');
            Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->whereUuid('conversation')->name('conversations.show');
            Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages'])->whereUuid('conversation')->name('conversations.messages');
            Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])->whereUuid('conversation')->middleware('throttle:communication-send')->name('messages.store');
            Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read'])->whereUuid('conversation')->middleware('throttle:120,1')->name('conversations.read');
            Route::post('/conversations/{conversation}/typing', [ConversationController::class, 'typing'])->whereUuid('conversation')->middleware('throttle:communication-typing')->name('conversations.typing');
            Route::post('/conversations/{conversation}/mute', [ConversationController::class, 'mute'])->whereUuid('conversation')->name('conversations.mute');
            Route::get('/conversations/{conversation}/pins', [ConversationController::class, 'pins'])->whereUuid('conversation')->name('conversations.pins');
            Route::get('/conversations/{conversation}/files', [ConversationController::class, 'files'])->whereUuid('conversation')->name('conversations.files');
            Route::post('/conversations/{conversation}/attachments', [AttachmentController::class, 'store'])->whereUuid('conversation')->middleware('throttle:communication-upload')->name('attachments.store');

            Route::patch('/messages/{message}', [MessageController::class, 'update'])->whereUuid('message')->middleware('throttle:communication-send')->name('messages.update');
            Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->whereUuid('message')->name('messages.destroy');
            Route::post('/messages/{message}/reactions', [MessageController::class, 'react'])->whereUuid('message')->middleware('throttle:communication-react')->name('messages.react');
            Route::delete('/messages/{message}/reactions', [MessageController::class, 'react'])->whereUuid('message')->middleware('throttle:communication-react')->name('messages.unreact');
            Route::post('/messages/{message}/pin', [MessageController::class, 'pin'])->whereUuid('message')->name('messages.pin');
            Route::delete('/messages/{message}/pin', [MessageController::class, 'pin'])->whereUuid('message')->name('messages.unpin');
            Route::post('/messages/{message}/forward', [MessageController::class, 'forward'])->whereUuid('message')->middleware('throttle:communication-send')->name('messages.forward');

            Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->whereUuid('attachment')->name('attachments.download');
            Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereUuid('attachment')->name('attachments.destroy');

            Route::get('/directory', [DirectoryController::class, 'search'])->middleware('throttle:communication-search')->name('directory.search');
            Route::get('/directory/{user}/photo', [DirectoryController::class, 'photo'])->whereNumber('user')->name('directory.photo');
            Route::post('/presence', [DirectoryController::class, 'heartbeat'])->middleware('throttle:6,1')->name('presence');
        });
    });
