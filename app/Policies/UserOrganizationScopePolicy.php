<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class UserOrganizationScopePolicy
{
    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can('users.assignOrganizationScopes') || $actor->can('user-organization-scopes.viewAny');
    }

    public function create(User $actor): bool
    {
        return $actor->can('user-organization-scopes.create');
    }

    public function update(User $actor, UserOrganizationScope $scope): bool
    {
        return $actor->can('user-organization-scopes.update')
            && ($scope->organization_id === null
                ? $this->organizationScopeService->isUnrestricted($actor)
                : $this->organizationScopeService->canAssignUserToScope($actor, $scope->organization_id));
    }

    public function delete(User $actor, UserOrganizationScope $scope): bool
    {
        return $actor->can('user-organization-scopes.delete')
            && ($scope->organization_id === null
                ? $this->organizationScopeService->isUnrestricted($actor)
                : $this->organizationScopeService->canAssignUserToScope($actor, $scope->organization_id));
    }

    public function restore(User $actor, UserOrganizationScope $scope): bool
    {
        return $actor->can('user-organization-scopes.restore');
    }
}
