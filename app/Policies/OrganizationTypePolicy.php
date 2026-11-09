<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationType;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class OrganizationTypePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('organization-types.viewAny');
    }

    public function view(User $user, OrganizationType $type): bool
    {
        return $user->can('organization-types.view');
    }

    public function create(User $user): bool
    {
        return $user->can('organization-types.create');
    }

    public function update(User $user, OrganizationType $type): bool
    {
        return $user->can('organization-types.update');
    }

    public function delete(User $user, OrganizationType $type): bool
    {
        return $user->can('organization-types.delete');
    }

    public function restore(User $user, OrganizationType $type): bool
    {
        return $user->can('organization-types.restore');
    }

    public function viewDeleted(User $user): bool
    {
        return $user->can('organization-types.viewDeleted');
    }
}
