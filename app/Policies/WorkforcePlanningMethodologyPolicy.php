<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkforcePlanningMethodology;
use App\Policies\Concerns\DeniesNonAdminUsers;

/** City-level methodologies: planners read, stewards draft, approvers publish. */
readonly class WorkforcePlanningMethodologyPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('methodologies.viewAny') || $user->can('workforce-planning.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('methodologies.manage');
    }

    public function update(User $user, WorkforcePlanningMethodology $methodology): bool
    {
        return $user->can('methodologies.manage');
    }

    public function version(User $user, WorkforcePlanningMethodology $methodology): bool
    {
        return $user->can('methodologies.manage');
    }

    public function publish(User $user, WorkforcePlanningMethodology $methodology): bool
    {
        return $user->can('methodologies.approve');
    }
}
