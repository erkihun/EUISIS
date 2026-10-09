<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CafeteriaSpecialDay;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class CafeteriaSpecialDayPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_special_days.view');
    }

    public function view(User $user, CafeteriaSpecialDay $specialDay): bool
    {
        return $user->can('cafeteria_special_days.view');
    }

    public function create(User $user): bool
    {
        return $user->can('cafeteria_special_days.create');
    }

    public function update(User $user, CafeteriaSpecialDay $specialDay): bool
    {
        return $user->can('cafeteria_special_days.update');
    }

    public function archive(User $user, CafeteriaSpecialDay $specialDay): bool
    {
        return $user->can('cafeteria_special_days.archive');
    }

    public function restore(User $user, CafeteriaSpecialDay $specialDay): bool
    {
        return $user->can('cafeteria_special_days.restore');
    }
}
