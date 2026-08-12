<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Grievance;
use App\Models\User;

class GrievancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'grievances.view',
            'grievances.manage',
            'grievances.committee',
            'grievances.chairperson',
            'grievances.manager',
            'grievances.tribunal',
        ]);
    }

    public function view(User $user, Grievance $grievance): bool
    {
        if ($grievance->isOwnedBy($user)) {
            return true;
        }

        if ($user->hasAnyPermission(['grievances.manage', 'grievances.tribunal'])) {
            return true;
        }

        if ($user->hasAnyPermission(['grievances.committee', 'grievances.chairperson', 'grievances.manager'])) {
            return $grievance->organization_id === $user->organizationScopes()->first()?->organization_id
                || $user->hasRole('Super Admin')
                || $user->hasRole('City Admin');
        }

        return false;
    }

    public function create(User $user): bool
    {
        return true; // any authenticated user can submit
    }

    /**
     * Gate for admin-only grievance configuration (categories, SLA rules) —
     * distinct from create()/update(), which are deliberately permissive so
     * any employee can file/edit their own draft grievance.
     */
    public function manage(User $user): bool
    {
        return $user->hasAnyPermission(['grievances.manage']);
    }

    public function update(User $user, Grievance $grievance): bool
    {
        if ($grievance->isOwnedBy($user) && $grievance->status->value === 'draft') {
            return true;
        }

        return $user->hasAnyPermission(['grievances.manage']);
    }

    public function assign(User $user, Grievance $grievance): bool
    {
        return $user->hasAnyPermission(['grievances.manage']);
    }

    public function checkRequirement(User $user, Grievance $grievance): bool
    {
        return $user->hasAnyPermission(['grievances.manage', 'grievances.committee']);
    }

    public function draftResponse(User $user, Grievance $grievance): bool
    {
        return $user->hasAnyPermission(['grievances.committee']);
    }

    public function compileResponse(User $user, Grievance $grievance): bool
    {
        return $user->hasAnyPermission(['grievances.chairperson']);
    }

    public function approveResponse(User $user, Grievance $grievance): bool
    {
        return $user->hasAnyPermission(['grievances.manager']);
    }

    public function generateLetter(User $user, Grievance $grievance): bool
    {
        return $user->hasAnyPermission(['grievances.manager', 'grievances.manage']);
    }

    public function delete(User $user, Grievance $grievance): bool
    {
        return $user->hasAnyPermission(['grievances.manage']) && $grievance->status->value === 'draft';
    }
}
