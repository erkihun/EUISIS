<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class RolePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('roles.viewAny');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        if ($role->isProtected() && ! $user->hasRole('Super Admin')) {
            return false;
        }

        if ($role->isGlobal() && ! $user->hasAnyRole(['Super Admin', 'System Admin'])) {
            return false;
        }

        return $user->can('roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        if ($role->isProtected()) {
            return false;
        }

        return $user->can('roles.delete');
    }

    public function assignPermissions(User $user, Role $role): bool
    {
        if ($role->isProtected() && ! $user->hasRole('Super Admin')) {
            return false;
        }

        return $user->can('roles.assignPermissions');
    }
}
