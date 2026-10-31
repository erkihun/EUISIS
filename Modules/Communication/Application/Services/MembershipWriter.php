<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Communication\Application\Events\ConversationCreated;
use Modules\Communication\Application\Events\ConversationMemberAdded;
use Modules\Communication\Application\Events\ConversationMemberRemoved;
use Modules\Communication\Application\Events\MessageSent;
use Modules\Communication\Application\Presenters\MessageResource;
use Modules\Communication\Domain\Enums\MemberRole;
use Modules\Communication\Domain\Enums\MembershipSource;
use Modules\Communication\Domain\Enums\MessageType;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\ConversationMember;
use Modules\Communication\Domain\Models\Message;

/**
 * The one place memberships are written for groups and channels.
 *
 * - Adding reactivates a former member's row (history is kept, never
 *   duplicated) and starts their read pointer at the latest message, so a
 *   newcomer is not handed the whole history as unread.
 * - Removing sets left_at; the row stays for history and audit.
 * - System notes ("X added Y") are stored as an event plus names and shown in
 *   the reader's language; they never count as unread.
 */
final class MembershipWriter
{
    private const CHUNK = 500;

    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CommunicationInbox $inbox,
        private readonly CommunicationBroadcaster $broadcaster,
    ) {}

    /**
     * @param  iterable<int|string>  $userIds
     * @return list<int> the user ids that became members
     */
    public function add(Conversation $conversation, iterable $userIds, MembershipSource $source, ?User $actor, MemberRole $role = MemberRole::Member): array
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $now = now();
        $lastId = Conversation::query()->whereKey($conversation->getKey())->value('last_message_id');
        $added = [];

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            DB::transaction(function () use ($conversation, $chunk, $source, $actor, $role, $now, $lastId, &$added): void {
                $existing = ConversationMember::query()->where('conversation_id', $conversation->getKey())
                    ->whereIn('user_id', $chunk->all())->get(['id', 'user_id', 'left_at'])->keyBy('user_id');

                $returning = $existing->filter(fn (ConversationMember $m) => $m->left_at !== null)->keys()->all();
                if ($returning !== []) {
                    ConversationMember::query()->where('conversation_id', $conversation->getKey())->whereIn('user_id', $returning)->update([
                        'left_at' => null, 'left_reason' => null, 'role' => $role->value, 'source' => $source->value, 'added_by' => $actor?->getKey(),
                        'joined_at' => $now, 'last_read_message_id' => $lastId, 'last_delivered_message_id' => $lastId,
                        'last_read_at' => $now, 'muted_until' => null, 'updated_at' => $now,
                    ]);
                }

                $fresh = $chunk->reject(fn (int $id) => $existing->has($id))->values();
                if ($fresh->isNotEmpty()) {
                    ConversationMember::query()->insert($fresh->map(fn (int $id) => [
                        'id' => (string) Str::uuid7(), 'conversation_id' => $conversation->getKey(), 'user_id' => $id,
                        'role' => $role->value, 'source' => $source->value, 'added_by' => $actor?->getKey(), 'joined_at' => $now,
                        'last_read_message_id' => $lastId, 'last_delivered_message_id' => $lastId, 'last_read_at' => $now,
                        'created_at' => $now, 'updated_at' => $now,
                    ])->all());
                }

                array_push($added, ...array_map('intval', $returning), ...$fresh->all());
            });
        }

        $this->access->forget();

        return $added;
    }

    /**
     * @param  iterable<int|string>  $userIds
     * @param  'LEFT'|'REMOVED'|'SYNC'  $reason
     * @return list<int> the user ids that stopped being members
     */
    public function remove(Conversation $conversation, iterable $userIds, string $reason): array
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        $removed = [];
        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $active = ConversationMember::query()->where('conversation_id', $conversation->getKey())
                ->whereIn('user_id', $chunk->all())->whereNull('left_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if ($active === []) {
                continue;
            }
            ConversationMember::query()->where('conversation_id', $conversation->getKey())->whereIn('user_id', $active)
                ->update(['left_at' => now(), 'left_reason' => $reason, 'updated_at' => now()]);
            array_push($removed, ...$active);
        }
        $this->access->forget();
        $this->inbox->forgetTotals(...$removed);

        return $removed;
    }

    /**
     * Tells open pages about the change: each newcomer's list gains the
     * conversation, and the conversation's members see who joined or left.
     * Large automatic syncs stay quiet per person (the lists poll).
     *
     * @param  list<int>  $added
     * @param  list<int>  $removed
     */
    public function announce(Conversation $conversation, array $added, array $removed, int $perPersonLimit = 50): void
    {
        if ($added !== []) {
            $this->inbox->forgetTotals(...$added);
            $this->broadcaster->send(new ConversationCreated($conversation->getKey(), array_slice($added, 0, $perPersonLimit)));
        }
        foreach (array_slice($added, 0, $perPersonLimit) as $id) {
            $this->broadcaster->send(new ConversationMemberAdded($conversation->getKey(), $id));
        }
        foreach (array_slice($removed, 0, $perPersonLimit) as $id) {
            $this->broadcaster->send(new ConversationMemberRemoved($conversation->getKey(), $id));
        }
    }

    /** Posts "actor did X (to subject)" in the conversation. */
    public function note(Conversation $conversation, User $actor, string $event, ?string $subjectName = null): Message
    {
        $message = DB::transaction(function () use ($conversation, $actor, $event, $subjectName): Message {
            $message = (new Message)->forceFill([
                'conversation_id' => $conversation->getKey(),
                'sender_id' => $actor->getKey(),
                'type' => MessageType::System,
                'body' => null,
                'metadata' => array_filter(['system_event' => $event, 'actor_name' => $actor->name, 'subject_name' => $subjectName], fn ($v) => $v !== null),
            ]);
            $message->save();
            Conversation::query()->whereKey($conversation->getKey())->update(['last_message_id' => $message->getKey(), 'last_message_at' => $message->created_at]);

            return $message;
        });
        $conversation->forceFill(['last_message_id' => $message->getKey(), 'last_message_at' => $message->created_at]);
        $message->load(['sender:id,name', 'attachments', 'reactions', 'mentions', 'parent']);
        $this->broadcaster->send(new MessageSent($conversation->getKey(), $message->getKey(), (int) $actor->getKey(), (new MessageResource($message))->resolve()));

        return $message;
    }

    /** Hands ownership to the longest-standing admin, else member; null when nobody is left. */
    public function promoteSuccessor(Conversation $conversation, int $leavingUserId): ?int
    {
        $next = ConversationMember::query()->where('conversation_id', $conversation->getKey())->whereNull('left_at')
            ->where('user_id', '<>', $leavingUserId)
            ->orderByRaw("case when role = 'admin' then 0 else 1 end")->orderBy('joined_at')->orderBy('id')
            ->first(['id', 'user_id']);
        if ($next === null) {
            return null;
        }
        ConversationMember::query()->whereKey($next->getKey())->update(['role' => MemberRole::Owner->value, 'updated_at' => now()]);
        $this->access->forget();

        return (int) $next->user_id;
    }
}
