<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CafeteriaProvider;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\Cafeteria\CafeteriaProviderAccessService;

readonly class CafeteriaProviderPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_providers.viewAny');
    }

    public function view(User $user, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria_providers.view')
            && app(CafeteriaProviderAccessService::class)->canAccessProvider($user, $provider);
    }

    public function create(User $user): bool
    {
        return $user->can('cafeteria_providers.create');
    }

    public function update(User $user, CafeteriaProvider $_provider): bool
    {
        return $user->can('cafeteria_providers.update');
    }

    public function updateInstitution(User $user, CafeteriaProvider $_provider): bool
    {
        // Which organizations' employees a provider serves: its own permission.
        return $user->can('cafeteria_providers.assignInstitution');
    }

    public function archive(User $user, CafeteriaProvider $_provider): bool
    {
        return $user->can('cafeteria_providers.archive');
    }

    public function restore(User $user, CafeteriaProvider $_provider): bool
    {
        return $user->can('cafeteria_providers.restore');
    }
}
