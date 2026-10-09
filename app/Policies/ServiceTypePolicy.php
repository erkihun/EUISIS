<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceType;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class ServiceTypePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('service-types.viewAny');
    }

    public function view(User $user, ServiceType $serviceType): bool
    {
        return $user->can('service-types.view');
    }

    public function create(User $user): bool
    {
        return $user->can('service-types.create');
    }

    public function update(User $user, ServiceType $serviceType): bool
    {
        return $user->can('service-types.update');
    }

    public function archive(User $user, ServiceType $serviceType): bool
    {
        return $user->can('service-types.delete') || $user->can('service-types.archive');
    }

    public function restore(User $user, ServiceType $serviceType): bool
    {
        return $user->can('service-types.restore');
    }

    public function viewDeleted(User $user): bool
    {
        return $user->can('service-types.viewDeleted');
    }
}
