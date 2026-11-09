<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Policies;

use App\Models\User;
use Modules\Communication\Application\Services\CommunicationAccess;
use Modules\Communication\Application\Services\CommunicationSettings;
use Modules\Communication\Domain\Enums\MessageType;
use Modules\Communication\Domain\Models\Message;

/** What a member may do with one message. Every rule needs current membership first. */
class MessagePolicy
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CommunicationSettings $settings,
    ) {}

    public function view(User $user, Message $message): bool
    {
        return $this->access->canRead($user, $message->conversation);
    }

    public function update(User $user, Message $message): bool
    {
        return $this->ownLive($user, $message)
            && in_array($message->type, [MessageType::Text, MessageType::Image, MessageType::File], true)
            && $this->settings->allowEditing() && $user->can('messages.edit_own')
            && $message->created_at->gte(now()->subMinutes($this->settings->editWindowMinutes()));
    }

    /**
     * The sender inside the delete window; or a group/channel admin holding
     * messages.delete_any in that conversation (moderator removals of reported
     * content go through the report, ModerateReportedMessageAction).
     */
    public function delete(User $user, Message $message): bool
    {
        if ($message->trashed() || ! $this->access->canRead($user, $message->conversation)) {
            return false;
        }
        if ($this->ownLive($user, $message) && $this->settings->allowDeletion() && $user->can('messages.delete_own')
            && $message->created_at->gte(now()->subMinutes($this->settings->deleteWindowMinutes()))) {
            return true;
        }

        return $user->can('messages.delete_any') && (bool) $this->access->membership($user, $message->conversation)?->role->canManage();
    }

    public function react(User $user, Message $message): bool
    {
        return ! $message->trashed() && $message->type !== MessageType::System && $this->access->canRead($user, $message->conversation)
            && ! $message->conversation->isArchived();
    }

    public function forward(User $user, Message $message): bool
    {
        return ! $message->trashed() && $message->type !== MessageType::System && $this->settings->allowForwarding()
            && $user->can('messages.forward') && $this->access->canRead($user, $message->conversation);
    }

    public function convertToTask(User $user, Message $message): bool
    {
        return ! $message->trashed() && $user->can('communication.tasks.create') && $this->access->canRead($user, $message->conversation);
    }

    public function report(User $user, Message $message): bool
    {
        return $message->sender_id !== $user->getKey() && $user->can('communication.reports.create') && $this->access->canRead($user, $message->conversation);
    }

    private function ownLive(User $user, Message $message): bool
    {
        return ! $message->trashed() && $message->sender_id === $user->getKey() && $this->access->canRead($user, $message->conversation)
            && ! $message->conversation->isArchived();
    }
}
