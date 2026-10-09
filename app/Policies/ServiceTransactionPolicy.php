<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceTransaction;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class ServiceTransactionPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('transactions.view');
    }

    public function view(User $user, ServiceTransaction $serviceTransaction): bool
    {
        return $user->can('transactions.view')
            && $serviceTransaction->employee !== null
            && $this->organizationScopeService->canAccessEmployee($user, $serviceTransaction->employee);
    }
}
