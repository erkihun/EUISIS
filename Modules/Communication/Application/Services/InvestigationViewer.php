<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Modules\Communication\Domain\Enums\AccessRequestStatus;
use Modules\Communication\Domain\Enums\AccessScopeType;
use Modules\Communication\Domain\Models\CommunicationAccessRequest;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\ConversationMember;
use Modules\Communication\Domain\Models\Message;

/**
 * Read-only access to private conversations under one approved, unexpired
 * investigation request, for the person who asked for it and nobody else.
 * Every page read is audited with the conversation and the message ids shown.
 * Nothing here posts, reacts, marks as read or downloads files.
 */
final class InvestigationViewer
{
    public const PAGE = 50;

    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** Throws unless this is the investigator's own request and it is in force now. */
    public function assertInForce(User $investigator, CommunicationAccessRequest $request): void
    {
        if ((int) $request->requested_by !== (int) $investigator->getKey() || ! $request->isInForce() || ! $investigator->checkPermissionTo('communication.break_glass.request')) {
            throw new AuthorizationException;
        }
    }

    /** @return Builder<Conversation> */
    public function conversationsQuery(CommunicationAccessRequest $request): Builder
    {
        return $request->scope_type === AccessScopeType::Conversation
            ? Conversation::query()->whereKey($request->conversation_id)
            : Conversation::query()->whereIn('id', ConversationMember::query()->select('conversation_id')->where('user_id', $request->target_user_id));
    }

    /** @return list<array<string, mixed>> */
    public function conversations(User $investigator, CommunicationAccessRequest $request, string $locale): array
    {
        $this->assertInForce($investigator, $request);
        $rows = $this->conversationsQuery($request)->with(['channel'])->orderByDesc('last_message_at')->limit(200)->get();
        $members = ConversationMember::query()->whereIn('conversation_id', $rows->modelKeys())->with('user:id,name')->get()->groupBy('conversation_id');
        $this->use($investigator, $request, ['listed_conversations' => $rows->count()]);

        return $rows->map(fn (Conversation $c) => [
            'id' => $c->getKey(),
            'type' => $c->type->value,
            'title' => $c->channel?->displayName($locale) ?? $c->title,
            'members' => ($members->get($c->getKey()) ?? collect())->map(fn ($m) => $m->user?->name)->filter()->take(8)->values(),
            'member_count' => ($members->get($c->getKey()) ?? collect())->count(),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ])->values()->all();
    }

    /** @return array{data: list<array<string, mixed>>, next_before: ?string} */
    public function messages(User $investigator, CommunicationAccessRequest $request, Conversation $conversation, ?string $before): array
    {
        $this->assertInForce($investigator, $request);
        if (! $this->conversationsQuery($request)->whereKey($conversation->getKey())->exists()) {
            throw new AuthorizationException;
        }
        $rows = Message::query()->withTrashed()->where('conversation_id', $conversation->getKey())
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::PAGE + 1)->with(['sender:id,name', 'attachments'])->get();
        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);
        $this->use($investigator, $request, ['conversation_id' => $conversation->getKey(), 'message_ids' => $rows->modelKeys()]);

        return [
            'data' => $rows->reverse()->map(fn (Message $m) => [
                'id' => $m->getKey(),
                'type' => $m->type->value,
                'sender' => $m->sender?->name,
                'body' => $m->body,
                'metadata' => $m->metadata,
                'deleted' => $m->trashed(),
                'edited_at' => $m->edited_at?->toIso8601String(),
                'attachments' => $m->attachments->map(fn ($a) => ['name' => $a->original_name, 'size' => $a->size, 'mime_type' => $a->mime_type])->values(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
            'next_before' => $more ? $rows->last()?->getKey() : null,
        ];
    }

    /** @param array<string, mixed> $what */
    private function use(User $investigator, CommunicationAccessRequest $request, array $what): void
    {
        if ($request->status === AccessRequestStatus::Approved) {
            $request->forceFill(['status' => AccessRequestStatus::Active])->save();
        }
        $this->audit->execute(AuditEventType::CommunicationAccessUsed, $investigator, $request, null, null,
            ['case_reference' => $request->case_reference, ...$what]);
    }
}
