<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CafeteriaDayRule;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class CafeteriaDayRulePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_day_rules.view');
    }

    public function view(User $user, CafeteriaDayRule $rule): bool
    {
        return $user->can('cafeteria_day_rules.view');
    }

    public function update(User $user, CafeteriaDayRule $rule): bool
    {
        return $user->can('cafeteria_day_rules.update');
    }
}
