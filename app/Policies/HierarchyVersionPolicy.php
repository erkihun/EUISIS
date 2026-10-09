<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class HierarchyVersionPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('hierarchy-versions.viewAny');
    }

    public function view(User $user, HierarchyVersion $hierarchyVersion): bool
    {
        return $user->can('hierarchy-versions.view');
    }

    public function viewTree(User $user, HierarchyVersion $hierarchyVersion): bool
    {
        return $this->view($user, $hierarchyVersion);
    }

    public function create(User $user): bool
    {
        return $user->can('hierarchy-versions.create');
    }

    public function update(User $user, HierarchyVersion $hierarchyVersion): bool
    {
        return $user->can('hierarchy-versions.update')
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }

    public function archive(User $user, HierarchyVersion $hierarchyVersion): bool
    {
        return $user->can('hierarchy-versions.archive')
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }

    public function publish(User $user, HierarchyVersion $hierarchyVersion): bool
    {
        return $user->can('hierarchy-versions.publish')
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }

    public function manageTree(User $user, HierarchyVersion $hierarchyVersion): bool
    {
        return $user->can('hierarchy-versions.manageTree')
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }
}
