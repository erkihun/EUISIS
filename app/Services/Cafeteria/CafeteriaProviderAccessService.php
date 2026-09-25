<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Models\CafeteriaProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CafeteriaProviderAccessService
{
    /** @return list<string> Empty means all providers. */
    public function accessibleProviderIds(User $user): array
    {
        if ($this->canAccessAllProviders($user)) {
            return [];
        }

        $today = Carbon::today()->toDateString();

        return $user->cafeteriaProviders()
            ->wherePivot('is_active', true)
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('cafeteria_provider_assignments.effective_from')
                    ->orWhere('cafeteria_provider_assignments.effective_from', '<=', $today);
            })
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('cafeteria_provider_assignments.effective_to')
                    ->orWhere('cafeteria_provider_assignments.effective_to', '>=', $today);
            })
            ->pluck('cafeteria_providers.id')
            ->all();
    }

    public function canAccessProvider(User $user, CafeteriaProvider|string $provider): bool
    {
        if ($this->canAccessAllProviders($user)) {
            return true;
        }

        $providerId = $provider instanceof CafeteriaProvider ? $provider->id : $provider;

        return in_array($providerId, $this->accessibleProviderIds($user), true);
    }

    /** @param Builder<Model> $query */
    public function filterProviderScopedQuery(User $user, Builder $query, string $providerColumn = 'cafeteria_provider_id'): Builder
    {
        $providerIds = $this->accessibleProviderIds($user);

        if ($providerIds === []) {
            return $query;
        }

        return $query->whereIn($providerColumn, $providerIds);
    }

    public function canAccessAllProviders(User $user): bool
    {
        // Unassigned oversight of every provider; others see their assigned providers only.
        return $user->can('cafeteria_providers.viewAll');
    }
}
