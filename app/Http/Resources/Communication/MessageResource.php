<?php

declare(strict_types=1);

namespace App\Http\Resources\Communication;

use App\Models\Communication\Message;
use App\Models\Communication\MessageAttachment;
use App\Models\Communication\MessageReaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One message as a member sees it. Viewer-neutral (the same payload is
 * broadcast to every member; "mine" is derived from sender.id on the client).
 * A deleted message carries no body, no attachments and no reactions.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Message $m */
        $m = $this->resource;
        $deleted = $m->trashed();

        return [
            'id' => $m->getKey(),
            'conversation_id' => $m->conversation_id,
            'type' => $m->type->value,
            'sender' => $m->sender_id === null ? null : ['id' => $m->sender_id, 'name' => $m->sender?->name],
            'body' => $deleted ? null : $m->body,
            'deleted' => $deleted,
            'edited_at' => $deleted ? null : $m->edited_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
            'forwarded' => $m->forwarded_from_message_id !== null,
            'metadata' => $deleted ? null : $this->safeMetadata($m->metadata),
            'parent' => $m->parent_message_id === null ? null : $this->parentSummary($m),
            'attachments' => $deleted ? [] : $m->attachments->map(fn (MessageAttachment $a) => self::attachment($a))->values(),
            'reactions' => $deleted ? [] : self::reactionSummary($m->reactions),
            'mentions' => $deleted ? [] : $m->mentions->filter(fn ($x) => $x->mentioned_user_id !== null)
                ->map(fn ($x) => ['user_id' => $x->mentioned_user_id, 'name' => $x->mentionedUser?->name])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function attachment(MessageAttachment $a): array
    {
        return [
            'id' => $a->getKey(),
            'name' => $a->original_name,
            'mime_type' => $a->mime_type,
            'extension' => $a->extension,
            'size' => $a->size,
            'is_image' => $a->isImage(),
            'width' => $a->metadata['width'] ?? null,
            'height' => $a->metadata['height'] ?? null,
            'downloadable' => $a->scan_status->isDownloadable(),
            'scan_status' => $a->scan_status->value,
        ];
    }

    /**
     * @param  iterable<MessageReaction>  $reactions
     * @return list<array{emoji: string, count: int, user_ids: list<int>}>
     */
    public static function reactionSummary(iterable $reactions): array
    {
        return collect($reactions)->groupBy('emoji')->map(fn ($group, $emoji) => [
            'emoji' => (string) $emoji,
            'count' => $group->count(),
            'user_ids' => $group->pluck('user_id')->map(fn ($id) => (int) $id)->take(50)->values()->all(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function parentSummary(Message $m): array
    {
        $p = $m->parent;
        if ($p === null) {
            return ['id' => $m->parent_message_id, 'deleted' => true, 'excerpt' => null, 'sender_name' => null];
        }

        return [
            'id' => $p->getKey(),
            'deleted' => $p->trashed(),
            'excerpt' => $p->trashed() ? null : Str::limit((string) $p->body, 160),
            'sender_name' => $p->sender?->name,
            'has_attachments' => ! $p->trashed() && $p->attachments()->exists(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function safeMetadata(?array $metadata): ?array
    {
        return $metadata === null ? null : array_intersect_key($metadata, array_flip(['task_id', 'system_event', 'actor_name', 'subject_name']));
    }
}
