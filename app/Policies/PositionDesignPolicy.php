<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\JobDescription;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Position profiles, work links, requirements (position-profiles.*) and job
 * descriptions (job-descriptions.*). Registered for PositionProfile and
 * JobDescription; class-based abilities take the Position.
 */
readonly class PositionDesignPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('position-profiles.viewAny') || $user->can('job-descriptions.viewAny');
    }

    /** Reading a position's design page. */
    public function viewPosition(User $user, Position $position): bool
    {
        return $this->viewAny($user) && $this->scope->canAccess($user, $position->organization_id);
    }

    /** Drafting a profile, syncing links and requirements for a position. */
    public function designPosition(User $user, Position $position): bool
    {
        return $user->can('position-profiles.manage') && $this->scope->canAccess($user, $position->organization_id);
    }

    public function view(User $user, PositionProfile|JobDescription $record): bool
    {
        return $this->viewAny($user) && $this->scope->canAccess($user, $record->organization_id);
    }

    public function update(User $user, PositionProfile|JobDescription $record): bool
    {
        $permission = $record instanceof JobDescription ? 'job-descriptions.manage' : 'position-profiles.manage';

        return $user->can($permission) && $this->scope->canAccess($user, $record->organization_id);
    }

    public function approve(User $user, PositionProfile|JobDescription $record): bool
    {
        $permission = $record instanceof JobDescription ? 'job-descriptions.approve' : 'position-profiles.approve';

        return $user->can($permission) && $this->scope->canAccess($user, $record->organization_id);
    }

    public function publish(User $user, JobDescription $jd): bool
    {
        return $user->can('job-descriptions.publish') && $this->scope->canAccess($user, $jd->organization_id);
    }

    /** Drafting a job description for a position. */
    public function draftJobDescription(User $user, Position $position): bool
    {
        return $user->can('job-descriptions.manage') && $this->scope->canAccess($user, $position->organization_id);
    }
}
