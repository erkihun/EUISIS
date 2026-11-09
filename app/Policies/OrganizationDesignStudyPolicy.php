<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/** Design studies, their proposals and unit responsibilities. Permission says WHAT; scope says WHERE. */
readonly class OrganizationDesignStudyPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('organization-design.viewAny');
    }

    public function view(User $user, OrganizationDesignStudy $study): bool
    {
        return $user->can('organization-design.view') && $this->scope->canAccess($user, $study->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('organization-design.create');
    }

    public function update(User $user, OrganizationDesignStudy $study): bool
    {
        return $user->can('organization-design.update') && $this->scope->canAccess($user, $study->organization_id);
    }

    public function review(User $user, OrganizationDesignStudy $study): bool
    {
        return $user->can('organization-design.review') && $this->scope->canAccess($user, $study->organization_id);
    }

    public function approve(User $user, OrganizationDesignStudy $study): bool
    {
        return $user->can('organization-design.approve') && $this->scope->canAccess($user, $study->organization_id);
    }

    /** Mapping responsibilities on an existing unit is a design edit on that unit's organization. */
    public function mapUnit(User $user, OrganizationUnit $unit): bool
    {
        return $user->can('organization-design.update') && $this->scope->canAccess($user, $unit->organization_id);
    }

    /** Reading the responsibility matrix of an organization. */
    public function viewMatrix(User $user, string $organizationId): bool
    {
        return $user->can('organization-design.viewAny') && $this->scope->canAccess($user, $organizationId);
    }
}
