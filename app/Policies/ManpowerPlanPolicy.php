<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ManpowerPlan;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/** Manpower plans and their lines. Permission says WHAT; scope says WHERE. */
readonly class ManpowerPlanPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('workforce-planning.viewAny');
    }

    public function view(User $user, ManpowerPlan $plan): bool
    {
        return $user->can('workforce-planning.view') && $this->scope->canAccess($user, $plan->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('workforce-planning.create');
    }

    public function update(User $user, ManpowerPlan $plan): bool
    {
        return $user->can('workforce-planning.update') && $this->scope->canAccess($user, $plan->organization_id);
    }

    public function review(User $user, ManpowerPlan $plan): bool
    {
        return $user->can('workforce-planning.review') && $this->scope->canAccess($user, $plan->organization_id);
    }

    public function approve(User $user, ManpowerPlan $plan): bool
    {
        return $user->can('workforce-planning.approve') && $this->scope->canAccess($user, $plan->organization_id);
    }

    public function implement(User $user, ManpowerPlan $plan): bool
    {
        return $user->can('workforce-planning.implement') && $this->scope->canAccess($user, $plan->organization_id);
    }
}
