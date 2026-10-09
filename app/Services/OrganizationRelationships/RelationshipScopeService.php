<?php

declare(strict_types=1);

namespace App\Services\OrganizationRelationships;

use App\Models\InstitutionOfficeRelationship;
use App\Models\OrganizationUnitRelationship;
use App\Models\User;

readonly class RelationshipScopeService
{
    public function canViewFunctionalReports(User $user): bool
    {
        return $user->can('functional-reporting.viewReports')
            || $user->can('functional-reporting.view')
            || $user->can('relationships.viewAny');
    }

    public function canManageOfficeRelationship(User $user, ?InstitutionOfficeRelationship $relationship = null): bool
    {
        return $user->isSuperAdmin()
            || $user->can('relationships.update')
            || $user->can('relationships.create');
    }

    public function canManageUnitRelationship(User $user, ?OrganizationUnitRelationship $relationship = null): bool
    {
        return $user->isSuperAdmin()
            || $user->can('relationships.update')
            || $user->can('relationships.create');
    }
}
