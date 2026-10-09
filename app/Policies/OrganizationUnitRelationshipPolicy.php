<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationUnitRelationship;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

readonly class OrganizationUnitRelationshipPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('relationships.viewAny') || $user->can('relationships.view');
    }

    public function view(User $user, OrganizationUnitRelationship $relationship): bool
    {
        return $user->can('relationships.view') || $user->can('relationships.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('relationships.create');
    }

    public function update(User $user, OrganizationUnitRelationship $relationship): bool
    {
        return $user->can('relationships.update');
    }

    public function delete(User $user, OrganizationUnitRelationship $relationship): bool
    {
        return $user->can('relationships.delete');
    }

    public function restore(User $user, OrganizationUnitRelationship $relationship): bool
    {
        return $user->can('relationships.restore');
    }
}
