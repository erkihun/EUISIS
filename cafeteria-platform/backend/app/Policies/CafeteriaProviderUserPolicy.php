<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderUser;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\Cafeteria\CafeteriaProviderAccessService;

readonly class CafeteriaProviderUserPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private CafeteriaProviderAccessService $providerAccess) {}

    public function viewAny(User $user, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.viewAny')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }

    public function view(User $user, CafeteriaProviderUser $providerUser, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.view')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }

    public function create(User $user, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.create')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }

    public function update(User $user, CafeteriaProviderUser $providerUser, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.update')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }

    public function resetPassword(User $user, CafeteriaProviderUser $providerUser, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.resetPassword')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }

    public function suspend(User $user, CafeteriaProviderUser $providerUser, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.suspend')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }

    public function activate(User $user, CafeteriaProviderUser $providerUser, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.activate')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }

    public function delete(User $user, CafeteriaProviderUser $providerUser, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.delete')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }

    public function restore(User $user, CafeteriaProviderUser $providerUser, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria-provider-users.restore')
            && $this->providerAccess->canAccessProvider($user, $provider);
    }
}
