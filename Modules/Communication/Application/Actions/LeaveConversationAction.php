<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Services\MembershipWriter;
use Modules\Communication\Domain\Enums\ChannelType;
use Modules\Communication\Domain\Enums\MemberRole;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\ConversationMember;

/**
 * A person leaves a group or channel. An owner who leaves hands ownership to
 * the longest-standing admin (else member); the last person out archives the
 * conversation. System-managed channels cannot be left.
 */
final class LeaveConversationAction
{
    public function __construct(
        private readonly MembershipWriter $writer,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(User $user, Conversation $conversation): void
    {
        if (! Gate::forUser($user)->allows('leave', $conversation)) {
            throw new AuthorizationException;
        }
        if ($conversation->channel?->type === ChannelType::SystemManaged) {
            throw ValidationException::withMessages(['conversation' => __('communication.errors.mandatory_member')]);
        }
        $row = ConversationMember::query()->where('conversation_id', $conversation->getKey())->where('user_id', $user->getKey())->whereNull('left_at')->firstOrFail();

        $successor = null;
        if ($row->role === MemberRole::Owner) {
            $successor = $this->writer->promoteSuccessor($conversation, (int) $user->getKey());
        }
        if (! $conversation->isArchived()) {
            $this->writer->note($conversation, $user, 'member_left');
        }
        $this->writer->remove($conversation, [$user->getKey()], 'LEFT');
        if ($row->role === MemberRole::Owner && $successor === null) {
            $conversation->forceFill(['archived_at' => now(), 'archived_by' => $user->getKey()])->save();
            $conversation->channel?->forceFill(['archived_at' => now()])->save();
        }
        $this->writer->announce($conversation, [], [(int) $user->getKey()]);
        $this->audit->execute(AuditEventType::CommunicationMemberRemoved, $user, $conversation, $conversation->organization_id,
            ['user_id' => $user->getKey(), 'role' => $row->role->value], ['left' => true, 'new_owner_id' => $successor]);
    }
}
