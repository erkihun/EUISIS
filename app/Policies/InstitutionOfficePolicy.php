<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstitutionOffice;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class InstitutionOfficePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('institution-offices.viewAny');
    }

    public function view(User $user, InstitutionOffice $office): bool
    {
        return $user->can('institution-offices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('institution-offices.create');
    }

    public function update(User $user, InstitutionOffice $office): bool
    {
        return $user->can('institution-offices.update');
    }

    public function delete(User $user, InstitutionOffice $office): bool
    {
        return $user->can('institution-offices.delete');
    }

    public function restore(User $user, InstitutionOffice $office): bool
    {
        return $user->can('institution-offices.restore');
    }

    public function forceDelete(User $user, InstitutionOffice $office): bool
    {
        // Permanent deletion is a recycle-bin action for every module.
        return $user->can('recycle-bin.forceDelete');
    }

    public function move(User $user, InstitutionOffice $office): bool
    {
        return $user->can('institution-offices.update');
    }

    public function viewTree(User $user): bool
    {
        return $user->can('institution-offices.viewAny');
    }

    public function export(User $user): bool
    {
        return $user->can('institution-offices.viewAny');
    }
}
