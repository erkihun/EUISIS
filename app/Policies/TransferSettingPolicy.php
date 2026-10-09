<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class TransferSettingPolicy
{
    use DeniesNonAdminUsers;

    public function view(User $user): bool
    {
        return $user->can('transfers.settings.manage') || $user->can('transfers.viewAny');
    }

    public function update(User $user): bool
    {
        return $user->can('transfers.settings.manage');
    }
}
