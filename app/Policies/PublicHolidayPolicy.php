<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PublicHoliday;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class PublicHolidayPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('public_holidays.viewAny');
    }

    public function view(User $user, PublicHoliday $holiday): bool
    {
        return $user->can('public_holidays.view');
    }

    public function create(User $user): bool
    {
        return $user->can('public_holidays.create');
    }

    public function update(User $user, PublicHoliday $holiday): bool
    {
        return $user->can('public_holidays.update');
    }

    public function archive(User $user, PublicHoliday $holiday): bool
    {
        return $user->can('public_holidays.archive');
    }
}
