<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationUnit;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class OrganizationUnitPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('organization-units.viewAny');
    }

    public function view(User $user, OrganizationUnit $unit): bool
    {
        return $user->can('organization-units.view')
            && $this->organizationScopeService->canAccessOrganization($user, $unit->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('organization-units.create');
    }

    public function update(User $user, OrganizationUnit $unit): bool
    {
        return $user->can('organization-units.update')
            && $this->organizationScopeService->canAccessOrganization($user, $unit->organization_id);
    }

    public function archive(User $user, OrganizationUnit $unit): bool
    {
        return ($user->can('organization-units.delete') || $user->can('organization-units.archive'))
            && $this->organizationScopeService->canAccessOrganization($user, $unit->organization_id);
    }

    public function restore(User $user, OrganizationUnit $unit): bool
    {
        return $user->can('organization-units.restore')
            && $this->organizationScopeService->canAccessOrganization($user, $unit->organization_id);
    }

    public function viewDeleted(User $user): bool
    {
        return $user->can('organization-units.viewDeleted');
    }

    public function manageHierarchy(User $user): bool
    {
        return $user->can('organization-units.manageHierarchy');
    }
}
