<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

/**
 * Administration of provider portal accounts (/provider-users). The
 * `cafeteria-provider-users.*` permissions cover every provider type.
 */
class ProviderUserPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria-provider-users.viewAny');
    }

    public function view(User $user, ProviderUser $providerUser): bool
    {
        return $user->can('cafeteria-provider-users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('cafeteria-provider-users.create');
    }

    public function update(User $user, ProviderUser $providerUser): bool
    {
        return $user->can('cafeteria-provider-users.update');
    }

    public function resetPassword(User $user, ProviderUser $providerUser): bool
    {
        return $user->can('cafeteria-provider-users.resetPassword');
    }

    public function suspend(User $user, ProviderUser $providerUser): bool
    {
        return $user->can('cafeteria-provider-users.suspend');
    }

    public function activate(User $user, ProviderUser $providerUser): bool
    {
        return $user->can('cafeteria-provider-users.activate');
    }

    public function delete(User $user, ProviderUser $providerUser): bool
    {
        return $user->can('cafeteria-provider-users.delete');
    }

    public function restore(User $user, ProviderUser $providerUser): bool
    {
        return $user->can('cafeteria-provider-users.restore');
    }
}
