<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\MemberRole;
use App\Events\Communication\ConversationMemberAdded;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use App\Services\Communication\CommunicationAccess;
use App\Services\Communication\CommunicationBroadcaster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Only the owner changes roles: member ⇄ admin, or hands ownership to another
 * member (the old owner becomes an admin). Exactly one owner at all times.
 */
final class ChangeMemberRoleAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CommunicationBroadcaster $broadcaster,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(User $actor, Conversation $conversation, User $target, MemberRole $role): void
    {
        if (! Gate::forUser($actor)->allows('changeRoles', $conversation)) {
            throw new AuthorizationException;
        }
        $row = ConversationMember::query()->where('conversation_id', $conversation->getKey())->where('user_id', $target->getKey())->whereNull('left_at')->first();
        if ($row === null || (int) $target->getKey() === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['role' => __('communication.errors.not_a_member')]);
        }
        $before = $row->role;
        if ($before === $role) {
            return;
        }

        DB::transaction(function () use ($conversation, $actor, $row, $role): void {
            if ($role === MemberRole::Owner) {
                ConversationMember::query()->where('conversation_id', $conversation->getKey())->where('user_id', $actor->getKey())
                    ->update(['role' => MemberRole::Admin->value, 'updated_at' => now()]);
            }
            $row->forceFill(['role' => $role])->save();
        });
        $this->access->forget();

        $this->audit->execute(AuditEventType::CommunicationMemberRoleChanged, $actor, $conversation, $conversation->organization_id,
            ['user_id' => $target->getKey(), 'role' => $before->value], ['user_id' => $target->getKey(), 'role' => $role->value]);
        // "Membership changed" for open pages, which re-read the member list.
        $this->broadcaster->send(new ConversationMemberAdded($conversation->getKey(), (int) $target->getKey()));
    }
}
