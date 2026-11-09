<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Communication\Http\Controllers\AnnouncementController;
use Modules\Communication\Http\Controllers\AttachmentController;
use Modules\Communication\Http\Controllers\BlockController;
use Modules\Communication\Http\Controllers\ChannelController;
use Modules\Communication\Http\Controllers\CommunicationController;
use Modules\Communication\Http\Controllers\CommunicationSettingsController;
use Modules\Communication\Http\Controllers\ConversationController;
use Modules\Communication\Http\Controllers\DirectoryController;
use Modules\Communication\Http\Controllers\GroupController;
use Modules\Communication\Http\Controllers\InvestigationController;
use Modules\Communication\Http\Controllers\MemberController;
use Modules\Communication\Http\Controllers\MessageController;
use Modules\Communication\Http\Controllers\MessagesController;
use Modules\Communication\Http\Controllers\ModerationController;
use Modules\Communication\Http\Controllers\PreferencesController;
use Modules\Communication\Http\Controllers\TaskController;
use Modules\Communication\Http\Middleware\EnsureCommunicationEnabled;

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

        Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');

        // Announcements: the reader's feed and page, authoring and reports.
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('throttle:30,1')->name('announcements.store');
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->whereUuid('announcement')->name('announcements.show');
        Route::get('/announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->whereUuid('announcement')->name('announcements.edit');
        Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->whereUuid('announcement')->middleware('throttle:30,1')->name('announcements.update');
        Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->whereUuid('announcement')->name('announcements.publish');
        Route::post('/announcements/{announcement}/cancel', [AnnouncementController::class, 'cancel'])->whereUuid('announcement')->name('announcements.cancel');
        Route::post('/announcements/{announcement}/acknowledge', [AnnouncementController::class, 'acknowledge'])->whereUuid('announcement')->middleware('throttle:60,1')->name('announcements.acknowledge');
        Route::get('/announcements/{announcement}/export', [AnnouncementController::class, 'export'])->whereUuid('announcement')->middleware('throttle:10,1')->name('announcements.export');
        Route::get('/announcement-files/{attachment}', [AnnouncementController::class, 'downloadAttachment'])->whereUuid('attachment')->name('announcements.files.download');

        Route::get('/preferences', [PreferencesController::class, 'index'])->name('preferences.index');
        Route::patch('/preferences', [PreferencesController::class, 'update'])->middleware('throttle:30,1')->name('preferences.update');

        // Tasks, moderation and investigation access.
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');
        Route::get('/investigations', [InvestigationController::class, 'index'])->name('investigations.index');
        Route::post('/investigations', [InvestigationController::class, 'store'])->middleware('throttle:10,1')->name('investigations.store');
        Route::get('/investigations/{investigation}', [InvestigationController::class, 'show'])->whereUuid('investigation')->name('investigations.show');
        Route::post('/investigations/{investigation}/decide', [InvestigationController::class, 'decide'])->whereUuid('investigation')->name('investigations.decide');
        Route::post('/investigations/{investigation}/revoke', [InvestigationController::class, 'revoke'])->whereUuid('investigation')->name('investigations.revoke');

        Route::prefix('api')->name('api.')->group(function (): void {
            Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
            Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:communication-start')->name('conversations.store');
            Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->whereUuid('conversation')->name('conversations.show');
            Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages'])->whereUuid('conversation')->name('conversations.messages');
            Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])->whereUuid('conversation')->middleware('throttle:communication-send')->name('messages.store');
            Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read'])->whereUuid('conversation')->middleware('throttle:120,1')->name('conversations.read');
            Route::post('/conversations/{conversation}/typing', [ConversationController::class, 'typing'])->whereUuid('conversation')->middleware('throttle:communication-typing')->name('conversations.typing');
            Route::post('/conversations/{conversation}/mute', [ConversationController::class, 'mute'])->whereUuid('conversation')->name('conversations.mute');
            Route::get('/conversations/{conversation}/search', [ConversationController::class, 'search'])->whereUuid('conversation')->middleware('throttle:communication-search')->name('conversations.search');
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

            // Groups and channels: creation, settings, membership.
            Route::post('/groups', [GroupController::class, 'store'])->middleware('throttle:communication-group')->name('groups.store');
            Route::patch('/conversations/{conversation}', [GroupController::class, 'update'])->whereUuid('conversation')->name('conversations.update');
            Route::post('/conversations/{conversation}/archive', [GroupController::class, 'archive'])->whereUuid('conversation')->name('conversations.archive');
            Route::get('/conversations/{conversation}/members', [MemberController::class, 'index'])->whereUuid('conversation')->name('members.index');
            Route::post('/conversations/{conversation}/members', [MemberController::class, 'store'])->whereUuid('conversation')->middleware('throttle:communication-group')->name('members.store');
            Route::delete('/conversations/{conversation}/members/{user}', [MemberController::class, 'destroy'])->whereUuid('conversation')->whereNumber('user')->name('members.destroy');
            Route::patch('/conversations/{conversation}/members/{user}', [MemberController::class, 'role'])->whereUuid('conversation')->whereNumber('user')->name('members.role');
            Route::post('/conversations/{conversation}/leave', [MemberController::class, 'leave'])->whereUuid('conversation')->name('conversations.leave');
            Route::get('/channels', [ChannelController::class, 'browse'])->middleware('throttle:communication-search')->name('channels.browse');
            Route::post('/channels', [ChannelController::class, 'store'])->middleware('throttle:communication-channel')->name('channels.store');
            Route::post('/channels/{channel}/join', [ChannelController::class, 'join'])->whereUuid('channel')->name('channels.join');
            Route::get('/channel-scopes/organizations', [ChannelController::class, 'organizations'])->middleware('throttle:communication-search')->name('channels.organizations');
            Route::get('/channel-scopes/units', [ChannelController::class, 'units'])->middleware('throttle:communication-search')->name('channels.units');

            Route::get('/announcements/feed', [AnnouncementController::class, 'feed'])->name('announcements.feed');
            Route::get('/announcements/managed', [AnnouncementController::class, 'managed'])->name('announcements.managed');
            Route::post('/announcements/audience', [AnnouncementController::class, 'audienceCount'])->middleware('throttle:communication-search')->name('announcements.audience');
            Route::get('/announcements/target-options', [AnnouncementController::class, 'targetOptions'])->middleware('throttle:communication-search')->name('announcements.targets');
            Route::get('/announcements/{announcement}/recipients', [AnnouncementController::class, 'recipients'])->whereUuid('announcement')->name('announcements.recipients');
            Route::post('/announcements/{announcement}/attachments', [AnnouncementController::class, 'storeAttachment'])->whereUuid('announcement')->middleware('throttle:communication-upload')->name('announcements.attachments.store');
            Route::delete('/announcement-files/{attachment}', [AnnouncementController::class, 'destroyAttachment'])->whereUuid('attachment')->name('announcements.attachments.destroy');

            Route::get('/tasks', [TaskController::class, 'list'])->name('tasks.list');
            Route::post('/messages/{message}/task', [TaskController::class, 'store'])->whereUuid('message')->middleware('throttle:30,1')->name('tasks.store');
            Route::patch('/tasks/{task}', [TaskController::class, 'update'])->whereUuid('task')->name('tasks.update');
            Route::post('/messages/{message}/report', [ModerationController::class, 'reportMessage'])->whereUuid('message')->middleware('throttle:communication-report')->name('reports.message');
            Route::post('/conversations/{conversation}/report', [ModerationController::class, 'reportConversation'])->whereUuid('conversation')->middleware('throttle:communication-report')->name('reports.conversation');
            Route::get('/reports', [ModerationController::class, 'list'])->name('reports.list');
            Route::get('/reports/{report}', [ModerationController::class, 'show'])->whereUuid('report')->name('reports.show');
            Route::post('/reports/{report}/resolve', [ModerationController::class, 'resolve'])->whereUuid('report')->name('reports.resolve');
            Route::get('/blocks', [BlockController::class, 'index'])->name('blocks.index');
            Route::post('/blocks', [BlockController::class, 'store'])->middleware('throttle:30,1')->name('blocks.store');
            Route::delete('/blocks/{user}', [BlockController::class, 'destroy'])->whereNumber('user')->name('blocks.destroy');
            Route::get('/investigations/people', [InvestigationController::class, 'people'])->middleware('throttle:communication-search')->name('investigations.people');
            Route::get('/investigations/{investigation}/conversations/{conversation}', [InvestigationController::class, 'messages'])->whereUuid('investigation')->whereUuid('conversation')->middleware('throttle:60,1')->name('investigations.messages');

            Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->whereUuid('attachment')->name('attachments.download');
            Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereUuid('attachment')->name('attachments.destroy');

            Route::get('/directory', [DirectoryController::class, 'search'])->middleware('throttle:communication-search')->name('directory.search');
            Route::get('/directory/{user}/photo', [DirectoryController::class, 'photo'])->whereNumber('user')->name('directory.photo');
            Route::post('/presence', [DirectoryController::class, 'heartbeat'])->middleware('throttle:6,1')->name('presence');
        });
    });

/*
 | System Settings → Communication sits outside the module switch: turning the
 | module off must not lock its administrators out of turning it back on.
*/
Route::middleware(['auth', 'mfa', 'force.password', 'admin.access'])
    ->prefix('communication/admin')->name('communication.settings.')
    ->group(function (): void {
        Route::get('/settings', [CommunicationSettingsController::class, 'index'])->name('index');
        Route::patch('/settings', [CommunicationSettingsController::class, 'update'])->middleware('throttle:30,1')->name('update');
    });
