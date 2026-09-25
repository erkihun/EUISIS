<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\Cafeteria\CafeteriaProviderAccessService;

readonly class CafeteriaTransactionPolicy
{
    use DeniesNonAdminUsers;

    /**
     * Override: allow ProviderUser to reach exportProviderTransactions,
     * which has a User|ProviderUser union type and handles both cases.
     * All other abilities are denied for non-admin users by the trait.
     */
    public function before(mixed $user, string $ability): ?bool
    {
        if (! $user instanceof User) {
            return $ability === 'exportProviderTransactions' ? null : false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_transactions.view');
    }

    public function view(User $user, CafeteriaTransaction $transaction): bool
    {
        return $user->can('cafeteria_transactions.view')
            && app(CafeteriaProviderAccessService::class)->canAccessProvider($user, $transaction->cafeteria_provider_id);
    }

    public function scan(User $user): bool
    {
        return $user->can('cafeteria_transactions.scan') || $user->hasRole(['Cafeteria Operator', 'Super Admin', 'City Admin']);
    }

    public function export(User $user): bool
    {
        return $this->viewAny($user) && ($user->can('cafeteria_reports.export')
            || $user->can('provider-cafeteria-transactions.export')
            || $user->can('provider-cafeteria-payment-claims.export'));
    }

    public function reverse(User $user, CafeteriaTransaction $transaction): bool
    {
        return $user->can('cafeteria_transactions.reverse')
            && app(CafeteriaProviderAccessService::class)->canAccessProvider($user, $transaction->cafeteria_provider_id);
    }

    public function exportProviderTransactions(User|ProviderUser $user, CafeteriaProvider $provider): bool
    {
        if ($user instanceof ProviderUser) {
            return $provider->is_active
                && $user->canLogin()
                && $user->hasService('cafeteria')
                && $user->provider?->cafeteriaProvider?->id === $provider->id;
        }

        return $provider->is_active
            && app(CafeteriaProviderAccessService::class)->canAccessProvider($user, $provider)
            && (
                $user->can('provider-cafeteria-transactions.export')
                || $user->can('cafeteria-portal.exportTransactions')
                || $user->can('provider-cafeteria-payment-claims.export')
                || $user->provider_portal_enabled
                || $user->user_type === 'provider'
            );
    }
}
