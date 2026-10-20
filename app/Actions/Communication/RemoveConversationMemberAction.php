<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\ChannelType;
use App\Enums\Communication\MemberRole;
use App\Enums\Communication\MembershipSource;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use App\Notifications\Communication\CommunicationNotification;
use App\Services\Communication\MembershipWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Removes someone from a group or channel. The owner cannot be removed (they
 * hand over ownership or leave); an admin removes members, only the owner
 * removes admins; a system-managed channel keeps its automatic members.
 * People leave with LeaveConversationAction, not here.
 */
final class RemoveConversationMemberAction
{
    public function __construct(
        private readonly MembershipWriter $writer,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(User $actor, Conversation $conversation, User $target): void
    {
        if (! Gate::forUser($actor)->allows('manageMembers', $conversation)) {
            throw new AuthorizationException;
        }
        $row = ConversationMember::query()->where('conversation_id', $conversation->getKey())->where('user_id', $target->getKey())->whereNull('left_at')->first();
        $mine = ConversationMember::query()->where('conversation_id', $conversation->getKey())->where('user_id', $actor->getKey())->whereNull('left_at')->first();
        $refuse = fn (string $key) => throw ValidationException::withMessages(['user' => __("communication.errors.{$key}")]);

        if ($row === null) {
            $refuse('not_a_member');
        }
        if ((int) $target->getKey() === (int) $actor->getKey()) {
            $refuse('use_leave');
        }
        if ($row->role === MemberRole::Owner) {
            $refuse('cannot_remove_owner');
        }
        // A member-admin cannot remove another admin; owners and scoped managers can.
        if ($row->role === MemberRole::Admin && $mine?->role === MemberRole::Admin) {
            throw new AuthorizationException;
        }
        if ($conversation->channel?->type === ChannelType::SystemManaged && $row->source === MembershipSource::Auto) {
            $refuse('mandatory_member');
        }

        $this->writer->remove($conversation, [$target->getKey()], 'REMOVED');
        $this->writer->note($conversation, $actor, 'member_removed', $target->name);
        $this->writer->announce($conversation, [], [(int) $target->getKey()]);
        $this->audit->execute(AuditEventType::CommunicationMemberRemoved, $actor, $conversation, $conversation->organization_id,
            ['user_id' => $target->getKey(), 'role' => $row->role->value, 'source' => $row->source->value], null);
        $target->notify(new CommunicationNotification('removed_from_group',
            ['name' => $actor->name, 'group' => $conversation->channel?->name ?? (string) $conversation->title], null));
    }
}
