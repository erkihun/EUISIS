<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceProvider;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class ServiceProviderPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('service-providers.viewAny');
    }

    public function view(User $user, ServiceProvider $serviceProvider): bool
    {
        return $user->can('service-providers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('service-providers.create');
    }

    public function update(User $user, ServiceProvider $serviceProvider): bool
    {
        return $user->can('service-providers.update');
    }

    public function delete(User $user, ServiceProvider $serviceProvider): bool
    {
        return $user->can('service-providers.delete');
    }
}
