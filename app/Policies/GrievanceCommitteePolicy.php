<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GrievanceCommittee;
use App\Models\User;

class GrievanceCommitteePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['grievances.manage', 'grievances.committee', 'grievances.chairperson']);
    }

    public function view(User $user, GrievanceCommittee $committee): bool
    {
        return $user->hasAnyPermission(['grievances.manage', 'grievances.committee', 'grievances.chairperson']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['grievances.manage']);
    }

    public function update(User $user, GrievanceCommittee $committee): bool
    {
        return $user->hasAnyPermission(['grievances.manage']);
    }

    public function delete(User $user, GrievanceCommittee $committee): bool
    {
        return $user->hasAnyPermission(['grievances.manage']);
    }
}
