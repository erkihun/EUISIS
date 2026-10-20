<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Events\Communication\MessageRead;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\Communication\Message;
use App\Models\User;
use App\Services\Communication\CommunicationAccess;
use App\Services\Communication\CommunicationBroadcaster;
use App\Services\Communication\CommunicationInbox;
use App\Services\Communication\CommunicationSettings;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Moves the member's read pointer forward (never back) to a message of this
 * conversation, or to its latest message. Read receipts are broadcast only in
 * small conversations and only when the system allows them.
 */
final class MarkConversationReadAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CommunicationInbox $inbox,
        private readonly CommunicationSettings $settings,
        private readonly CommunicationBroadcaster $broadcaster,
    ) {}

    public function execute(Conversation $conversation, User $user, ?string $upToMessageId = null): ?string
    {
        $member = $this->access->membership($user, $conversation);
        if ($member === null) {
            throw new AuthorizationException;
        }
        $target = $upToMessageId !== null
            ? Message::query()->withTrashed()->whereKey($upToMessageId)->where('conversation_id', $conversation->getKey())->value('id')
            : $conversation->fresh()?->last_message_id;
        if ($target === null || ($member->last_read_message_id !== null && strcmp((string) $member->last_read_message_id, (string) $target) >= 0)) {
            return $member->last_read_message_id;
        }

        ConversationMember::query()->whereKey($member->getKey())
            ->where(fn ($q) => $q->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $target))
            ->update(['last_read_message_id' => $target, 'last_read_at' => now()]);
        ConversationMember::query()->whereKey($member->getKey())
            ->where(fn ($q) => $q->whereNull('last_delivered_message_id')->orWhere('last_delivered_message_id', '<', $target))
            ->update(['last_delivered_message_id' => $target]);
        $this->access->forget($user, $conversation);
        $this->inbox->forgetTotals($user->getKey());

        if ($this->settings->allowReadReceipts()
            && $conversation->activeMembers()->count() <= (int) config('communication.receipts_max_members', 25)) {
            $this->broadcaster->send(new MessageRead($conversation->getKey(), (int) $user->getKey(), (string) $target));
        }

        return (string) $target;
    }
}
