<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Entitlement;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class EntitlementPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('entitlements.view');
    }

    public function view(User $user, Entitlement $entitlement): bool
    {
        return $user->can('entitlements.view') && $this->organizationScopeService->canAccessEmployee($user, $entitlement->employee);
    }

    public function create(User $user): bool
    {
        return $user->can('entitlements.manage');
    }
}
