<?php

declare(strict_types=1);

use App\Models\Communication\Conversation;
use App\Models\User;
use App\Services\Communication\CommunicationAccess;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/*
 * Private broadcast channels. Every subscription is checked here; the events
 * themselves carry ids and small facts, and the pages re-read over HTTP.
 *
 * Communication channels admit current members only. Super Admin has no
 * bypass (closures here do not pass through Gate::before), and break-glass
 * investigators read through the audited investigation views, never a live
 * subscription.
 */

Broadcast::channel('App.Models.User.{id}', fn (User $user, $id): bool => (int) $user->id === (int) $id);

Broadcast::channel('communication.user.{id}', fn (User $user, $id): bool => (int) $user->id === (int) $id
    && app(CommunicationAccess::class)->canUse($user));

Broadcast::channel('communication.conversation.{id}', function (User $user, string $id): bool {
    if (! Str::isUuid($id)) {
        return false;
    }
    $conversation = Conversation::query()->find($id);

    return $conversation !== null && app(CommunicationAccess::class)->canRead($user, $conversation);
});

Broadcast::channel('communication.announcements', fn (User $user): bool => app(CommunicationAccess::class)->canUse($user)
    && $user->can('announcements.view'));
