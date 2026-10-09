<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EntitlementRule;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class EntitlementRulePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('entitlement-rules.viewAny');
    }

    public function view(User $user, EntitlementRule $entitlementRule): bool
    {
        return $user->can('entitlement-rules.view');
    }

    public function create(User $user): bool
    {
        return $user->can('entitlement-rules.create');
    }

    public function update(User $user, EntitlementRule $entitlementRule): bool
    {
        return $user->can('entitlement-rules.update');
    }

    public function archive(User $user, EntitlementRule $entitlementRule): bool
    {
        return $user->can('entitlement-rules.delete') || $user->can('entitlement-rules.archive');
    }

    public function restore(User $user, EntitlementRule $entitlementRule): bool
    {
        return $user->can('entitlement-rules.restore');
    }

    public function viewDeleted(User $user): bool
    {
        return $user->can('entitlement-rules.viewDeleted');
    }
}
