<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class OrganizationEdgePolicy
{
    use DeniesNonAdminUsers;

    public function view(User $user, OrganizationEdge $organizationEdge): bool
    {
        return $user->can('organization-edges.view')
            || $user->can('hierarchy-versions.view');
    }

    public function create(User $user, HierarchyVersion $hierarchyVersion): bool
    {
        return $user->can('organization-edges.create')
            && $user->can('hierarchy-versions.manageTree')
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }

    public function update(User $user, OrganizationEdge $organizationEdge): bool
    {
        return $user->can('organization-edges.update')
            && $user->can('hierarchy-versions.manageTree')
            && $organizationEdge->hierarchyVersion?->status === HierarchyVersionStatus::Draft;
    }

    public function delete(User $user, OrganizationEdge $organizationEdge): bool
    {
        return $user->can('organization-edges.remove')
            && $user->can('hierarchy-versions.manageTree')
            && $organizationEdge->hierarchyVersion?->status === HierarchyVersionStatus::Draft;
    }
}
