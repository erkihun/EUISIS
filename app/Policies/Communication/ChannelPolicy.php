<?php

declare(strict_types=1);

namespace App\Policies\Communication;

use App\Enums\Communication\ChannelType;
use App\Models\Communication\Channel;
use App\Models\User;
use App\Services\Communication\CommunicationAccess;
use App\Services\Communication\CommunicationHierarchy;
use App\Services\Communication\CommunicationSettings;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Channels. A public organization channel is visible and joinable by people
 * placed in its organization (or under it, when it includes descendants); every
 * other channel is visible to its members only. Managing a channel never
 * grants reading it.
 */
class ChannelPolicy
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CommunicationSettings $settings,
        private readonly CommunicationHierarchy $hierarchy,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canUse($user) && $user->can('channels.view');
    }

    public function view(User $user, Channel $channel): bool
    {
        return $this->access->canRead($user, $channel->conversation)
            || ($channel->type === ChannelType::PublicOrganization && $this->join($user, $channel));
    }

    public function join(User $user, Channel $channel): bool
    {
        return $channel->type->isJoinable() && $channel->archived_at === null && $this->viewAny($user) && $this->placedIn($user, $channel);
    }

    /**
     * Organization-bound channels need channels.create in that organization's
     * scope; when the system allows employee channels, any user of the module
     * may also create a PRIVATE or PROJECT channel of their own.
     */
    public function create(User $user, ?string $organizationId = null, ?ChannelType $type = null): bool
    {
        if (! $this->access->canUse($user)) {
            return false;
        }
        if ($this->scope->canExercisePermission($user, 'channels.create', $organizationId)) {
            return true;
        }

        return $this->settings->allowEmployeeChannels() && in_array($type, [ChannelType::Private, ChannelType::Project], true);
    }

    public function update(User $user, Channel $channel): bool
    {
        if ($channel->archived_at !== null || ! $this->access->canUse($user)) {
            return false;
        }

        return (bool) $this->access->membership($user, $channel->conversation)?->role->canManage()
            || $this->scope->canExercisePermission($user, 'channels.manage', $channel->organization_id);
    }

    public function archive(User $user, Channel $channel): bool
    {
        return $channel->type !== ChannelType::SystemManaged || $this->scope->canExercisePermission($user, 'channels.manage', $channel->organization_id)
            ? $this->update($user, $channel)
            : false;
    }

    public function manageMembers(User $user, Channel $channel): bool
    {
        return $this->access->canManageMembers($user, $channel->conversation);
    }

    /** Placed (current assignment) in the channel's organization, or under it when it includes descendants. */
    public function placedIn(User $user, Channel $channel): bool
    {
        $assignment = $this->hierarchy->assignmentOf($user);
        if ($assignment === null) {
            return false;
        }
        if ($channel->organization_unit_id !== null) {
            $units = $channel->include_descendants
                ? $this->hierarchy->unitDescendants((string) $channel->organization_unit_id)
                : [(string) $channel->organization_unit_id];

            return in_array((string) $assignment->organization_unit_id, $units, true);
        }
        if ($channel->organization_id === null) {
            return true; // city-wide channel
        }
        $orgs = $channel->include_descendants
            ? $this->hierarchy->organizationDescendants((string) $channel->organization_id)
            : [(string) $channel->organization_id];

        return in_array((string) $assignment->organization_id, $orgs, true);
    }
}
