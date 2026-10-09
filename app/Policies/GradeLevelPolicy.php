<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GradeLevel;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class GradeLevelPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('grade-levels.viewAny');
    }

    public function view(User $user, GradeLevel $gradeLevel): bool
    {
        return $user->can('grade-levels.view');
    }

    public function create(User $user): bool
    {
        return $user->can('grade-levels.create');
    }

    public function update(User $user, GradeLevel $gradeLevel): bool
    {
        return $user->can('grade-levels.update');
    }

    public function archive(User $user, GradeLevel $gradeLevel): bool
    {
        return $user->can('grade-levels.delete') || $user->can('grade-levels.archive');
    }

    public function restore(User $user, GradeLevel $gradeLevel): bool
    {
        return $user->can('grade-levels.restore');
    }
}
