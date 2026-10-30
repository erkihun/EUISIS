<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Cafeteria\Application\Services\CafeteriaProviderAccessService;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;
use Modules\Cafeteria\Domain\Models\CafeteriaTransaction;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Providers\Contracts\ProviderPortalAccount;

readonly class CafeteriaTransactionPolicy
{
    use DeniesNonAdminUsers;

    /**
     * Override: allow a provider-portal user to reach exportProviderTransactions,
     * which handles both the staff and the portal case.
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

    public function exportProviderTransactions(Authenticatable $user, CafeteriaProvider $provider): bool
    {
        if (! $user instanceof User) {
            // A portal user (the provider guard): any cafeteria of their provider,
            // main or branch, not only the provider's first (main) cafeteria.
            $account = app(ProviderPortalAccount::class)->find((string) $user->getAuthIdentifier());

            return $provider->is_active
                && $account !== null
                && $account->canSignIn
                && app(ProviderDirectory::class)->hasService($account->providerId, 'cafeteria')
                && $provider->provider_id !== null
                && $provider->provider_id === $account->providerId;
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
