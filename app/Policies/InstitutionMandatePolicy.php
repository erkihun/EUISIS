<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstitutionMandate;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/** Permission says WHAT; OrganizationScopeService says WHERE. */
readonly class InstitutionMandatePolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('institution-mandates.viewAny');
    }

    public function view(User $user, InstitutionMandate $mandate): bool
    {
        return $user->can('institution-mandates.view') && $this->scope->canAccess($user, $mandate->organization_id);
    }

    /** Creating is checked against the target organization by the request. */
    public function create(User $user): bool
    {
        return $user->can('institution-mandates.create');
    }

    public function update(User $user, InstitutionMandate $mandate): bool
    {
        return $user->can('institution-mandates.update') && $this->scope->canAccess($user, $mandate->organization_id);
    }

    /** A new version is a new draft of this lineage. */
    public function version(User $user, InstitutionMandate $mandate): bool
    {
        return $user->can('institution-mandates.create') && $this->scope->canAccess($user, $mandate->organization_id);
    }

    public function publish(User $user, InstitutionMandate $mandate): bool
    {
        return $user->can('institution-mandates.approve') && $this->scope->canAccess($user, $mandate->organization_id);
    }

    public function manageFunctions(User $user, InstitutionMandate $mandate): bool
    {
        return $user->can('organization-functions.manage') && $this->scope->canAccess($user, $mandate->organization_id);
    }
}
