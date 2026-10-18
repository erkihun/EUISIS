<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\ConversationType;
use App\Models\Communication\AnnouncementReceipt;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\Communication\MessageMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Conversation lists and unread counts from read pointers
 * (docs/communication-module.md §Read state).
 *
 * Unread = messages after the member's last-read pointer, not sent by them, not
 * deleted — one indexed range per conversation on (conversation_id, id). The
 * sidebar totals are cached for a few seconds per person; they are counts
 * only, never content.
 */
final class CommunicationInbox
{
    public const TOTALS_TTL_SECONDS = 20;

    public function __construct(private readonly AnnouncementAudience $audience) {}

    /** Members of the user's active conversations, with an `unread_count` column. */
    public function memberships(User $user): Builder
    {
        $userId = (int) $user->getKey();

        return ConversationMember::query()
            ->where('conversation_members.user_id', $userId)
            ->whereNull('conversation_members.left_at')
            ->select('conversation_members.*')
            ->selectSub(fn ($q) => $q->from('messages')->selectRaw('count(*)')
                ->whereColumn('messages.conversation_id', 'conversation_members.conversation_id')
                ->whereNull('messages.deleted_at')
                ->where('messages.type', '<>', 'SYSTEM')
                ->where(fn ($s) => $s->whereNull('messages.sender_id')->orWhere('messages.sender_id', '<>', $userId))
                ->where(fn ($r) => $r->whereNull('conversation_members.last_read_message_id')
                    ->orWhereColumn('messages.id', '>', 'conversation_members.last_read_message_id')), 'unread_count');
    }

    /** @return array{direct: int, group: int, channel: int, mentions: int, announcements: int, total: int} */
    public function totals(User $user, bool $fresh = false): array
    {
        $key = "communication:totals:{$user->getKey()}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TOTALS_TTL_SECONDS, function () use ($user): array {
            $byType = DB::query()->fromSub($this->memberships($user)
                ->join('conversations', 'conversations.id', '=', 'conversation_members.conversation_id')
                ->whereNull('conversations.archived_at')
                ->where(fn ($q) => $q->whereNull('conversation_members.muted_until')->orWhere('conversation_members.muted_until', '<', now()))
                ->addSelect('conversations.type as conversation_type'), 'm')
                ->selectRaw('conversation_type, sum(unread_count) as unread')->groupBy('conversation_type')
                ->pluck('unread', 'conversation_type');

            $direct = (int) ($byType[ConversationType::Direct->value] ?? 0);
            $group = (int) ($byType[ConversationType::Group->value] ?? 0);
            $channel = (int) ($byType[ConversationType::Channel->value] ?? 0);
            $mentions = $this->unreadMentions($user);
            $announcements = $this->unreadAnnouncements($user);

            return [
                'direct' => $direct, 'group' => $group, 'channel' => $channel,
                'mentions' => $mentions, 'announcements' => $announcements,
                'total' => $direct + $group + $channel + $announcements,
            ];
        });
    }

    public function forgetTotals(int|string ...$userIds): void
    {
        foreach ($userIds as $id) {
            Cache::forget("communication:totals:{$id}");
        }
    }

    public function unreadMentions(User $user): int
    {
        return MessageMention::query()
            ->join('conversation_members as cm', fn ($j) => $j->on('cm.conversation_id', '=', 'message_mentions.conversation_id')
                ->where('cm.user_id', '=', $user->getKey())->whereNull('cm.left_at'))
            ->join('messages', 'messages.id', '=', 'message_mentions.message_id')
            ->whereNull('messages.deleted_at')
            ->where('message_mentions.mentioned_user_id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('cm.last_read_message_id')->orWhereColumn('message_mentions.message_id', '>', 'cm.last_read_message_id'))
            ->count();
    }

    public function unreadAnnouncements(User $user): int
    {
        if (! $user->can('announcements.view')) {
            return 0;
        }

        return $this->audience->liveFor($user)
            ->whereNotIn('announcements.id', AnnouncementReceipt::query()->select('announcement_id')
                ->where('user_id', $user->getKey())->whereNotNull('viewed_at'))
            ->count();
    }

    /** The recipient fetched their inbox: everything up to each conversation's last message is delivered. */
    public function markDelivered(User $user): void
    {
        ConversationMember::query()->where('user_id', $user->getKey())->whereNull('left_at')
            ->update(['last_delivered_message_id' => Conversation::query()->select('last_message_id')
                ->whereColumn('conversations.id', 'conversation_members.conversation_id')->limit(1)]);
    }
}
