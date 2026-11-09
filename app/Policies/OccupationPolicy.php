<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Occupation;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class OccupationPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('occupations.viewAny');
    }

    public function view(User $user, Occupation $occupation): bool
    {
        return $user->can('occupations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('occupations.create');
    }

    public function update(User $user, Occupation $occupation): bool
    {
        return $user->can('occupations.update');
    }

    public function archive(User $user, Occupation $occupation): bool
    {
        return $user->can('occupations.delete') || $user->can('occupations.archive');
    }

    public function restore(User $user, Occupation $occupation): bool
    {
        return $user->can('occupations.restore');
    }

    public function export(User $user): bool
    {
        return $user->can('occupations.export');
    }
}
