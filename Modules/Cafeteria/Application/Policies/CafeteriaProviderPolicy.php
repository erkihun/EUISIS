<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use Modules\Cafeteria\Application\Services\CafeteriaProviderAccessService;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;

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

    public function update(User $user, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria_providers.update') && $this->canAccess($user, $provider);
    }

    public function updateInstitution(User $user, CafeteriaProvider $provider): bool
    {
        // Which organizations' employees a provider serves: its own permission.
        return $user->can('cafeteria_providers.assignInstitution') && $this->canAccess($user, $provider);
    }

    public function archive(User $user, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria_providers.archive') && $this->canAccess($user, $provider);
    }

    public function restore(User $user, CafeteriaProvider $provider): bool
    {
        return $user->can('cafeteria_providers.restore') && $this->canAccess($user, $provider);
    }

    /** Changing a provider needs the same provider access as viewing it (SEC-7). */
    private function canAccess(User $user, CafeteriaProvider $provider): bool
    {
        return app(CafeteriaProviderAccessService::class)->canAccessProvider($user, $provider);
    }
}
