<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IsicActivity;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class IsicActivityPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('isic-activities.viewAny');
    }

    public function view(User $user, IsicActivity $isicActivity): bool
    {
        return $user->can('isic-activities.view');
    }

    public function create(User $user): bool
    {
        return $user->can('isic-activities.create');
    }

    public function update(User $user, IsicActivity $isicActivity): bool
    {
        return $user->can('isic-activities.update');
    }

    public function archive(User $user, IsicActivity $isicActivity): bool
    {
        return $user->can('isic-activities.delete') || $user->can('isic-activities.archive');
    }

    public function restore(User $user, IsicActivity $isicActivity): bool
    {
        return $user->can('isic-activities.restore');
    }

    public function export(User $user): bool
    {
        return $user->can('isic-activities.export');
    }
}
