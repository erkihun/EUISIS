<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\AccessRequestStatus;
use App\Enums\Communication\AccessScopeType;
use App\Enums\Communication\ChannelPostingPolicy;
use App\Enums\Communication\ConversationType;
use App\Models\Communication\CommunicationAccessRequest;
use App\Models\Communication\CommunicationBlock;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * The one place that decides who may read or act in a conversation
 * (docs/communication-module.md §Privacy).
 *
 * Reading needs an active membership. No role or permission opens a private
 * conversation: Super Admin's gate bypass defers to the policies for these
 * models, and investigation access is a separate, approved, time-boxed grant
 * (investigationGrant) that is audited on every use.
 */
final class CommunicationAccess
{
    /** @var array<string, ConversationMember|false> */
    private array $memberships = [];

    public function __construct(
        private readonly CommunicationSettings $settings,
        private readonly OrganizationScopeService $scope,
    ) {}

    /** The module is on and the person may use it. */
    public function canUse(User $user): bool
    {
        return $this->settings->enabled() && $user->status === 'active' && $user->can('communication.access');
    }

    public function membership(User $user, Conversation $conversation): ?ConversationMember
    {
        $key = $user->getKey().':'.$conversation->getKey();
        if (! array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = ConversationMember::query()
                ->where('conversation_id', $conversation->getKey())->where('user_id', $user->getKey())
                ->whereNull('left_at')->first() ?? false;
        }

        return $this->memberships[$key] ?: null;
    }

    public function isMember(User $user, Conversation $conversation): bool
    {
        return $this->membership($user, $conversation) !== null;
    }

    public function canRead(User $user, Conversation $conversation): bool
    {
        return $this->canUse($user) && $this->isMember($user, $conversation);
    }

    public function canPost(User $user, Conversation $conversation): bool
    {
        $member = $this->membership($user, $conversation);
        if ($member === null || $conversation->isArchived() || ! $this->canUse($user) || ! $user->can('messages.send')) {
            return false;
        }

        return match ($conversation->type) {
            ConversationType::Channel => $conversation->channel?->archived_at === null
                && ($conversation->channel?->posting_policy !== ChannelPostingPolicy::AdminsOnly || $member->role->canManage()),
            ConversationType::Direct => ! $this->directBlocked($conversation),
            ConversationType::Group => true,
        };
    }

    /** Owners and admins of a group or channel; scoped channel managers for channels. */
    public function canManageMembers(User $user, Conversation $conversation): bool
    {
        if (! $this->canUse($user) || $conversation->isArchived() || $conversation->type === ConversationType::Direct) {
            return false;
        }
        if ($this->membership($user, $conversation)?->role->canManage()) {
            return true;
        }

        return $conversation->type === ConversationType::Channel
            && $this->scope->canExercisePermission($user, 'channels.manage_members', $conversation->channel?->organization_id);
    }

    /** True when the two people of a direct conversation have blocked each other in either direction. */
    public function directBlocked(Conversation $conversation): bool
    {
        $ids = $conversation->members()->pluck('user_id')->all();

        return count($ids) === 2 && CommunicationBlock::between((int) $ids[0], (int) $ids[1]);
    }

    /**
     * An approved, unexpired break-glass grant of this user that covers the
     * conversation, or null. A grant for an employee covers the conversations
     * that employee is or was a member of.
     */
    public function investigationGrant(User $user, Conversation $conversation): ?CommunicationAccessRequest
    {
        if (! $user->checkPermissionTo('communication.break_glass.request')) {
            return null;
        }

        return CommunicationAccessRequest::query()
            ->where('requested_by', $user->getKey())
            ->whereIn('status', [AccessRequestStatus::Approved->value, AccessRequestStatus::Active->value])
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())->where('expires_at', '>', now())
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('scope_type', AccessScopeType::Conversation->value)->where('conversation_id', $conversation->getKey()))
                ->orWhere(fn ($e) => $e->where('scope_type', AccessScopeType::Employee->value)
                    ->whereIn('target_user_id', ConversationMember::query()->select('user_id')->where('conversation_id', $conversation->getKey()))))
            ->latest('starts_at')->first();
    }

    /** Membership changed: drop what this request remembered. */
    public function forget(?User $user = null, ?Conversation $conversation = null): void
    {
        if ($user === null) {
            $this->memberships = [];

            return;
        }
        unset($this->memberships[$user->getKey().':'.$conversation?->getKey()]);
    }
}
