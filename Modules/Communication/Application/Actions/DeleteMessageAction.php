<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Communication\Application\Events\MessageDeleted;
use Modules\Communication\Application\Services\CommunicationBroadcaster;
use Modules\Communication\Domain\Models\ConversationPin;
use Modules\Communication\Domain\Models\Message;

/**
 * Soft-deletes a message (kept for retention, shown as "This message was
 * deleted."). A removal by someone other than the sender is audited — the
 * audit row holds ids, never the message text.
 */
final class DeleteMessageAction
{
    public function __construct(
        private readonly CommunicationBroadcaster $broadcaster,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(Message $message, User $actor, bool $authorized = false, ?string $reason = null): void
    {
        if (! $authorized) {
            Gate::forUser($actor)->authorize('delete', $message);
        }
        if ($message->trashed()) {
            return;
        }

        DB::transaction(function () use ($message, $actor): void {
            $message->forceFill(['deleted_by' => $actor->getKey()])->save();
            $message->delete();
            ConversationPin::query()->where('message_id', $message->getKey())->delete();
        });

        if ($message->sender_id !== $actor->getKey()) {
            $this->audit->execute(AuditEventType::CommunicationMessageModeratorDeleted, $actor, $message, null, null,
                ['conversation_id' => $message->conversation_id, 'sender_id' => $message->sender_id, 'reason' => $reason]);
        }
        $this->broadcaster->send(new MessageDeleted($message->conversation_id, $message->getKey()));
    }
}
