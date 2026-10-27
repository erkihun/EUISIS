<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class CafeteriaSettingPolicy
{
    use DeniesNonAdminUsers;

    public function view(User $user): bool
    {
        return $user->can('cafeteria_settings.view');
    }

    public function update(User $user): bool
    {
        return $user->can('cafeteria_settings.update');
    }
}
