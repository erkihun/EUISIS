<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LegacyBaseline;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/** Permission says WHAT; OrganizationScopeService says WHERE. Maker ≠ checker is enforced by the service. */
readonly class LegacyBaselinePolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('legacy-baselines.viewAny');
    }

    public function view(User $user, LegacyBaseline $baseline): bool
    {
        return $user->can('legacy-baselines.view') && $this->scope->canAccess($user, $baseline->organization_id);
    }

    /** Creating is checked against the target organization by the request. */
    public function create(User $user): bool
    {
        return $user->can('legacy-baselines.create');
    }

    public function update(User $user, LegacyBaseline $baseline): bool
    {
        return $user->can('legacy-baselines.update') && $this->scope->canAccess($user, $baseline->organization_id);
    }

    public function review(User $user, LegacyBaseline $baseline): bool
    {
        return $user->can('legacy-baselines.review') && $this->scope->canAccess($user, $baseline->organization_id);
    }

    public function approve(User $user, LegacyBaseline $baseline): bool
    {
        return $user->can('legacy-baselines.approve') && $this->scope->canAccess($user, $baseline->organization_id);
    }

    public function publish(User $user, LegacyBaseline $baseline): bool
    {
        return $user->can('legacy-baselines.publish') && $this->scope->canAccess($user, $baseline->organization_id);
    }
}
