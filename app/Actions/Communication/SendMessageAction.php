<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Enums\Communication\ConversationType;
use App\Enums\Communication\MentionType;
use App\Enums\Communication\MessageType;
use App\Events\Communication\MessageSent;
use App\Http\Resources\Communication\MessageResource;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\Communication\Message;
use App\Models\Communication\MessageAttachment;
use App\Models\Communication\MessageMention;
use App\Models\User;
use App\Notifications\Communication\CommunicationNotification;
use App\Services\Communication\CommunicationAccess;
use App\Services\Communication\CommunicationBroadcaster;
use App\Services\Communication\CommunicationInbox;
use App\Services\Communication\MentionParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stores a message (docs/communication-module.md §Messages).
 *
 * - Idempotent per sender and client message id, so a retried send after a
 *   dropped connection is never stored twice.
 * - A reply references one parent message of the same conversation.
 * - Attachments must be the sender's own unsent uploads to this conversation.
 * - `<!channel>` / `<!everyone>` need an owner or admin of the group or channel;
 *   personal mentions reach current members only.
 * - The message is committed first; the broadcast and notifications follow and
 *   may fail without affecting it.
 */
final class SendMessageAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly MentionParser $mentions,
        private readonly CommunicationBroadcaster $broadcaster,
        private readonly CommunicationInbox $inbox,
    ) {}

    /**
     * @param  array{body?: ?string, parent_message_id?: ?string, attachment_ids?: list<string>, client_message_id?: ?string, forwarded_from_message_id?: ?string, type?: MessageType, metadata?: array<string, mixed>}  $data
     */
    public function execute(Conversation $conversation, User $sender, array $data): Message
    {
        if (! $this->access->canPost($sender, $conversation)) {
            throw new AuthorizationException;
        }
        $clientId = isset($data['client_message_id']) ? (string) $data['client_message_id'] : null;
        if ($clientId !== null && ($existing = Message::query()->withTrashed()->where('sender_id', $sender->getKey())->where('client_message_id', $clientId)->first()) !== null) {
            return $existing;
        }

        $body = isset($data['body']) ? rtrim((string) $data['body']) : '';
        $attachmentIds = array_values(array_unique($data['attachment_ids'] ?? []));
        if ($body === '' && $attachmentIds === [] && ! isset($data['type'])) {
            throw ValidationException::withMessages(['body' => __('communication.errors.empty_message')]);
        }

        $parentId = $data['parent_message_id'] ?? null;
        if ($parentId !== null && ! Message::query()->withTrashed()->whereKey($parentId)->where('conversation_id', $conversation->getKey())->exists()) {
            throw ValidationException::withMessages(['parent_message_id' => __('communication.errors.parent_invalid')]);
        }

        $parsed = $this->mentions->parse($body);
        $member = $this->access->membership($sender, $conversation);
        if (($parsed['channel'] || $parsed['everyone'])
            && ($conversation->type === ConversationType::Direct || ! $member?->role->canManage())) {
            throw ValidationException::withMessages(['body' => __('communication.errors.broadcast_mention_forbidden')]);
        }

        [$message, $previousLastId, $mentionedIds] = DB::transaction(function () use ($conversation, $sender, $data, $body, $attachmentIds, $parentId, $parsed, $clientId): array {
            $attachments = $attachmentIds === [] ? collect() : MessageAttachment::query()->whereIn('id', $attachmentIds)
                ->where('conversation_id', $conversation->getKey())->where('uploaded_by', $sender->getKey())
                ->whereNull('message_id')->lockForUpdate()->get();
            if ($attachments->count() !== count($attachmentIds)) {
                throw ValidationException::withMessages(['attachment_ids' => __('communication.errors.attachment_invalid')]);
            }

            $type = $data['type'] ?? match (true) {
                $body !== '' => MessageType::Text,
                $attachments->every(fn (MessageAttachment $a) => $a->isImage()) => MessageType::Image,
                default => MessageType::File,
            };

            $message = (new Message)->forceFill([
                'conversation_id' => $conversation->getKey(),
                'sender_id' => $sender->getKey(),
                'parent_message_id' => $parentId,
                'forwarded_from_message_id' => $data['forwarded_from_message_id'] ?? null,
                'type' => $type,
                'body' => $body === '' ? null : $body,
                'metadata' => $data['metadata'] ?? null,
                'client_message_id' => $clientId,
            ]);
            $message->save();
            if ($attachments->isNotEmpty()) {
                MessageAttachment::query()->whereIn('id', $attachments->modelKeys())->update(['message_id' => $message->getKey()]);
            }

            // Personal mentions reach current members only; broadcast mentions are stored once.
            $mentionedIds = $parsed['users'] === [] ? [] : ConversationMember::query()->where('conversation_id', $conversation->getKey())
                ->whereNull('left_at')->whereIn('user_id', $parsed['users'])->where('user_id', '<>', $sender->getKey())
                ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $rows = array_map(fn (int $id) => ['mentioned_user_id' => $id, 'mention_type' => MentionType::User->value], $mentionedIds);
            foreach ($this->mentions->broadcasts($body) as $type) {
                $rows[] = ['mentioned_user_id' => null, 'mention_type' => $type->value];
            }
            foreach ($rows as $row) {
                (new MessageMention)->forceFill([...$row, 'message_id' => $message->getKey(), 'conversation_id' => $conversation->getKey()])->save();
            }

            $previousLastId = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->value('last_message_id');
            Conversation::query()->whereKey($conversation->getKey())->update(['last_message_id' => $message->getKey(), 'last_message_at' => $message->created_at]);
            // The sender has read their own message.
            ConversationMember::query()->where('conversation_id', $conversation->getKey())->where('user_id', $sender->getKey())
                ->update(['last_read_message_id' => $message->getKey(), 'last_read_at' => now(), 'last_delivered_message_id' => $message->getKey()]);

            return [$message, $previousLastId, $mentionedIds];
        });

        $conversation->forceFill(['last_message_id' => $message->getKey(), 'last_message_at' => $message->created_at]);
        $message->load(['sender:id,name', 'attachments', 'reactions', 'mentions.mentionedUser:id,name', 'parent.sender:id,name']);
        Cache::increment('communication:metrics:messages_sent');

        $this->broadcaster->send(new MessageSent($conversation->getKey(), $message->getKey(), (int) $sender->getKey(), (new MessageResource($message))->resolve()));
        $this->notify($conversation, $sender, $message, $previousLastId, $mentionedIds);

        return $message;
    }

    /** @param list<int> $mentionedIds */
    private function notify(Conversation $conversation, User $sender, Message $message, ?string $previousLastId, array $mentionedIds): void
    {
        $url = '/communication/messages/'.$conversation->getKey();
        $muted = ConversationMember::query()->where('conversation_id', $conversation->getKey())
            ->whereNotNull('muted_until')->where('muted_until', '>', now())->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        foreach (User::query()->whereIn('id', array_diff($mentionedIds, $muted))->get() as $user) {
            // Straight to the message that mentions them (the workspace scrolls to and highlights it).
            $user->notify(new CommunicationNotification('mentioned', ['name' => $sender->name], $url.'?message='.$message->getKey()));
        }

        if ($conversation->type !== ConversationType::Direct) {
            // Members whose badge should move; a very large channel's members catch up by polling.
            $members = ConversationMember::query()->where('conversation_id', $conversation->getKey())->whereNull('left_at')
                ->where('user_id', '<>', $sender->getKey())->whereNotIn('user_id', $muted)
                ->limit(CommunicationInbox::SIGNAL_LIMIT + 1)->pluck('user_id')->all();
            $this->inbox->forgetTotals($sender->getKey(), ...(count($members) > CommunicationInbox::SIGNAL_LIMIT ? [] : $members));

            return;
        }
        $recipient = ConversationMember::query()->where('conversation_id', $conversation->getKey())->whereNull('left_at')
            ->where('user_id', '<>', $sender->getKey())->first();
        if ($recipient === null) {
            return;
        }
        $this->inbox->forgetTotals($sender->getKey(), $recipient->user_id);
        // One notification per unread run: only when the recipient had read everything before this one.
        $caughtUp = $previousLastId === null || ($recipient->last_read_message_id !== null && strcmp((string) $recipient->last_read_message_id, (string) $previousLastId) >= 0);
        if ($caughtUp && ! in_array((int) $recipient->user_id, [...$muted, ...$mentionedIds], true)) {
            $recipient->user?->notify(new CommunicationNotification('direct_message', ['name' => $sender->name], $url));
        }
    }
}
