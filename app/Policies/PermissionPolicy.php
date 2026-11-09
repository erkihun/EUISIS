<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class PermissionPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('permissions.viewAny');
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->can('permissions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('permissions.create');
    }

    public function update(User $user, Permission $permission): bool
    {
        return $user->can('permissions.update');
    }

    public function delete(User $user, Permission $permission): bool
    {
        if ($permission->is_system) {
            return false;
        }

        return $user->can('permissions.delete');
    }
}
