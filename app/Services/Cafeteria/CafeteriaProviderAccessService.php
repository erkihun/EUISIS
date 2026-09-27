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
    /**
     * Assigned cafeteria ids. Empty for BOTH an oversight user (all) and a user
     * with no assignment (none): callers must decide with canAccessAllProviders(),
     * never by testing for an empty list.
     *
     * @return list<string>
     */
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
        // accessibleProviderIds() is also empty for a user with no assigned
        // cafeteria; that must mean none, not all. Only oversight sees all.
        if ($this->canAccessAllProviders($user)) {
            return $query;
        }

        return $query->whereIn($providerColumn, $this->accessibleProviderIds($user));
    }

    public function canAccessAllProviders(User $user): bool
    {
        // Unassigned oversight of every provider; others see their assigned providers only.
        return $user->can('cafeteria_providers.viewAll');
    }
}
