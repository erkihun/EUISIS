<?php

declare(strict_types=1);

namespace App\Policies\Communication;

use App\Enums\Communication\ConversationType;
use App\Enums\Communication\MemberRole;
use App\Models\Communication\Conversation;
use App\Models\User;
use App\Services\Communication\CommunicationAccess;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Conversations open by membership only. Super Admin's Gate::before bypass
 * defers to this policy for conversations (AppServiceProvider), so no role
 * reads a private conversation; investigation access is `investigate`.
 */
class ConversationPolicy
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->access->canRead($user, $conversation);
    }

    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $this->access->canPost($user, $conversation);
    }

    /** Rename, describe, change the image of a group (owners and admins). Channels: ChannelPolicy. */
    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->type === ConversationType::Group && ! $conversation->isArchived()
            && $this->access->canUse($user) && (bool) $this->access->membership($user, $conversation)?->role->canManage();
    }

    public function manageMembers(User $user, Conversation $conversation): bool
    {
        return $this->access->canManageMembers($user, $conversation);
    }

    /** Only the owner promotes or demotes admins. */
    public function changeRoles(User $user, Conversation $conversation): bool
    {
        return $conversation->type !== ConversationType::Direct && ! $conversation->isArchived()
            && $this->access->canUse($user) && $this->access->membership($user, $conversation)?->role === MemberRole::Owner;
    }

    /** A group's owner, or a group manager in its organization's scope (who still cannot read it). */
    public function archive(User $user, Conversation $conversation): bool
    {
        if ($conversation->type !== ConversationType::Group || $conversation->isArchived() || ! $this->access->canUse($user)) {
            return false;
        }

        return $this->access->membership($user, $conversation)?->role === MemberRole::Owner
            || ($conversation->organization_id !== null && $this->scope->canExercisePermission($user, 'groups.manage', $conversation->organization_id));
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return $conversation->type !== ConversationType::Direct && $this->access->isMember($user, $conversation);
    }

    /** Any member pins in direct and group conversations; channel admins in channels. */
    public function pin(User $user, Conversation $conversation): bool
    {
        $member = $this->access->membership($user, $conversation);
        if ($member === null || $conversation->isArchived() || ! $this->access->canUse($user)) {
            return false;
        }

        return $conversation->type !== ConversationType::Channel || $member->role->canManage();
    }

    public function mute(User $user, Conversation $conversation): bool
    {
        return $this->access->canRead($user, $conversation);
    }

    /** Read-only investigation view, only through an approved, unexpired break-glass grant. */
    public function investigate(User $user, Conversation $conversation): bool
    {
        return $this->access->investigationGrant($user, $conversation) !== null;
    }
}
