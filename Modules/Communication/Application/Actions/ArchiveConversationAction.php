<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Communication\Application\Services\CommunicationAccess;
use Modules\Communication\Domain\Enums\ConversationType;
use Modules\Communication\Domain\Models\Conversation;

/**
 * Archives a group or channel: it stays readable to its members, nobody can
 * post, and it leaves everyone's conversation list. Messages are kept.
 */
final class ArchiveConversationAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(User $actor, Conversation $conversation, ?string $reason = null): void
    {
        $channel = $conversation->type === ConversationType::Channel ? $conversation->channel : null;
        $allowed = $channel !== null ? Gate::forUser($actor)->allows('archive', $channel) : Gate::forUser($actor)->allows('archive', $conversation);
        if (! $allowed || $conversation->isArchived()) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($conversation, $channel, $actor): void {
            $conversation->forceFill(['archived_at' => now(), 'archived_by' => $actor->getKey()])->save();
            $channel?->forceFill(['archived_at' => now()])->save();
        });
        $this->access->forget();

        $this->audit->execute($channel ? AuditEventType::CommunicationChannelArchived : AuditEventType::CommunicationGroupArchived,
            $actor, $channel ?? $conversation, $conversation->organization_id, null, ['archived' => true], $reason);
    }
}
