<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\ConversationType;
use App\Enums\Communication\MessageType;
use App\Models\Communication\Channel;
use App\Models\Communication\CommunicationUserSetting;
use App\Models\Communication\ConversationMember;
use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * The person's conversation list, newest activity first, with keyset paging on
 * (last_message_at, conversation_id) — the same on PostgreSQL and SQLite and
 * stable while new messages arrive. Titles, last-message previews and the other
 * person of a direct conversation are loaded in a fixed number of queries.
 */
final class ConversationList
{
    public const PAGE = 30;

    public function __construct(
        private readonly CommunicationInbox $inbox,
        private readonly CommunicationDirectory $directory,
    ) {}

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function page(User $user, ?string $type, ?string $cursor, string $locale, int $limit = self::PAGE, ?string $search = null): array
    {
        $query = $this->inbox->memberships($user)
            ->join('conversations', 'conversations.id', '=', 'conversation_members.conversation_id')
            ->whereNull('conversations.archived_at')
            ->addSelect(['conversations.type as conversation_type', 'conversations.title as conversation_title',
                'conversations.last_message_id as conversation_last_message_id', 'conversations.last_message_at as conversation_last_message_at',
                'conversations.avatar_path as conversation_avatar'])
            ->orderByDesc('conversations.last_message_at')->orderByDesc('conversation_members.conversation_id');

        if ($type !== null && ConversationType::tryFrom($type) !== null) {
            $query->where('conversations.type', $type);
        }
        if ($search !== null && mb_strlen(trim($search)) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($search)).'%';
            $query->where(fn ($q) => $q->where('conversations.title', ci_like_operator(), $like)
                ->orWhereIn('conversations.id', Channel::query()->select('conversation_id')->where('name', ci_like_operator(), $like)->orWhere('name_am', ci_like_operator(), $like))
                ->orWhereIn('conversations.id', ConversationMember::query()->select('conversation_id')->whereNull('left_at')
                    ->whereIn('user_id', User::query()->select('id')->where('name', ci_like_operator(), $like))));
        }
        if ($cursor !== null && ($decoded = json_decode((string) base64_decode($cursor, true), true)) && count($decoded) === 2) {
            [$at, $id] = $decoded;
            $query->where(fn ($q) => $q->where('conversations.last_message_at', '<', $at)
                ->orWhere(fn ($e) => $e->where('conversations.last_message_at', $at)->where('conversation_members.conversation_id', '<', $id)));
        }

        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();

        return [
            'data' => $this->present($user, $rows, $locale),
            'next_cursor' => $more && $last !== null
                ? base64_encode((string) json_encode([(string) $last->getRawOriginal('conversation_last_message_at'), (string) $last->conversation_id]))
                : null,
        ];
    }

    /**
     * @param  Collection<int, ConversationMember>  $rows
     * @return list<array<string, mixed>>
     */
    public function present(User $user, Collection $rows, string $locale): array
    {
        $ids = $rows->pluck('conversation_id')->all();
        $directIds = $rows->where('conversation_type', ConversationType::Direct->value)->pluck('conversation_id')->all();
        $others = ConversationMember::query()->whereIn('conversation_id', $directIds)->where('user_id', '<>', $user->getKey())
            ->with('user:id,name,employee_id,status')->get()->keyBy('conversation_id');
        $otherSettings = CommunicationUserSetting::query()->whereIn('user_id', $others->pluck('user_id'))->get()->keyBy('user_id');
        $channels = Channel::query()->whereIn('conversation_id', $ids)->get(['id', 'conversation_id', 'name', 'name_am', 'slug', 'type'])->keyBy('conversation_id');
        $lastMessages = Message::query()->withTrashed()->whereIn('id', $rows->pluck('conversation_last_message_id')->filter())
            ->with('sender:id,name')->withCount('attachments')->get()->keyBy('id');
        // Mention tokens in the previews read as names, resolved in one query for the page.
        preg_match_all('/<@(\d{1,19})>/', $lastMessages->pluck('body')->implode(' '), $found);
        $mentionNames = $found[1] === [] ? collect() : User::query()->whereIn('id', array_unique($found[1]))->pluck('name', 'id');

        return $rows->map(function (ConversationMember $m) use ($others, $otherSettings, $channels, $lastMessages, $mentionNames, $locale): array {
            $type = (string) $m->conversation_type;
            $other = $others->get($m->conversation_id);
            $channel = $channels->get($m->conversation_id);
            $last = $lastMessages->get($m->conversation_last_message_id);
            $title = match ($type) {
                ConversationType::Direct->value => $other?->user?->name ?? __('communication.labels.unknown_person'),
                ConversationType::Channel->value => $channel?->displayName($locale) ?? (string) $m->conversation_title,
                default => (string) $m->conversation_title,
            };

            return [
                'id' => $m->conversation_id,
                'type' => $type,
                'title' => $title,
                'channel' => $channel ? ['id' => $channel->getKey(), 'slug' => $channel->slug, 'type' => $channel->type->value] : null,
                'other_user' => $other?->user ? ['id' => $other->user->getKey(), ...$this->directory->presence($otherSettings->get($other->user_id))] : null,
                'has_avatar' => filled($m->conversation_avatar),
                'role' => $m->role->value,
                'unread_count' => (int) $m->unread_count,
                'muted' => $m->isMuted(),
                'last_message_at' => $m->conversation_last_message_at ? Carbon::parse($m->conversation_last_message_at)->toIso8601String() : null,
                'last_message' => $last === null ? null : [
                    'sender_name' => $last->sender?->name,
                    'deleted' => $last->trashed(),
                    'excerpt' => $last->trashed() ? null : Str::limit($last->type === MessageType::System ? self::systemText($last->metadata ?? []) : self::readableBody((string) $last->body, $mentionNames), 90),
                    'has_attachments' => $last->attachments_count > 0,
                    'type' => $last->type->value,
                ],
            ];
        })->values()->all();
    }

    /** "<@12>" → "@Name", "<!everyone>" → "@everyone"; unknown people become a bare "@". */
    public static function readableBody(string $body, Collection $names): string
    {
        $text = preg_replace_callback('/<@(\d{1,19})>/', fn (array $m): string => '@'.($names->get((int) $m[1]) ?? ''), $body) ?? $body;

        return preg_replace('/<!(everyone|channel)>/', '@everyone', $text) ?? $text;
    }

    /** A system note ("X added Y") in the current language, from its stored event and names. */
    public static function systemText(array $metadata): string
    {
        $event = (string) ($metadata['system_event'] ?? '');
        $key = "communication.system.{$event}";

        return $event !== '' && Lang::has($key)
            ? (string) __($key, ['actor' => (string) ($metadata['actor_name'] ?? ''), 'subject' => (string) ($metadata['subject_name'] ?? '')])
            : '';
    }
}
