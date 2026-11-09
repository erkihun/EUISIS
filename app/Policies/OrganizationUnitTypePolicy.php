<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationUnitType;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class OrganizationUnitTypePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('organization-unit-types.viewAny');
    }

    public function view(User $user, OrganizationUnitType $type): bool
    {
        return $user->can('organization-unit-types.view');
    }

    public function create(User $user): bool
    {
        return $user->can('organization-unit-types.create');
    }

    public function update(User $user, OrganizationUnitType $type): bool
    {
        return $user->can('organization-unit-types.update');
    }

    public function archive(User $user, OrganizationUnitType $type): bool
    {
        return $user->can('organization-unit-types.delete') || $user->can('organization-unit-types.archive');
    }

    public function restore(User $user, OrganizationUnitType $type): bool
    {
        return $user->can('organization-unit-types.restore');
    }

    public function viewDeleted(User $user): bool
    {
        return $user->can('organization-unit-types.viewDeleted');
    }
}
