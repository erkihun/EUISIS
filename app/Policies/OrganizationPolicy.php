<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\Organizations\OrganizationDeletionGuard;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class OrganizationPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(
        private OrganizationScopeService $organizationScopeService,
        private OrganizationDeletionGuard $organizationDeletionGuard,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('organizations.view');
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->can('organizations.view')
            && $this->organizationScopeService->canAccessOrganization($user, $organization->id);
    }

    public function create(User $user): bool
    {
        if (! $user->can('organizations.create')) {
            return false;
        }

        return ! $this->organizationScopeService->mustCreateUnderAssignedOrganization($user)
            || $this->organizationScopeService->assignedOrganizationIds($user) !== [];
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->can('organizations.update')
            && $this->organizationScopeService->canManageWithinScope($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->can('organizations.delete')
            && $this->organizationScopeService->canManageWithinScope($user, $organization)
            && $this->organizationDeletionGuard->canBeDeletedSafely($organization);
    }

    public function archive(User $user, Organization $organization): bool
    {
        return $user->can('organizations.update')
            && $this->organizationScopeService->canManageWithinScope($user, $organization);
    }

    public function deactivate(User $user, Organization $organization): bool
    {
        return $user->can('organizations.update')
            && $this->organizationScopeService->canManageWithinScope($user, $organization);
    }

    public function restore(User $user, Organization $organization): bool
    {
        return $user->can('organizations.update')
            && $this->organizationScopeService->canManageWithinScope($user, $organization);
    }

    public function createChild(User $user, Organization $organization): bool
    {
        if (! $user->can('organizations.create')) {
            return false;
        }

        if ($organization->status !== OrganizationStatus::Active) {
            return false;
        }

        return $this->organizationScopeService->canCreateOrganizationUnder($user, $organization);
    }

    public function manageHierarchy(User $user): bool
    {
        return $user->can('organizations.manage');
    }

    /**
     * Gate for the Organization Structure Import wizard (upload → preview →
     * confirm). Deliberately its own permission rather than folding into
     * `organizations.manage`: bulk import writes units, positions, employees
     * and assignments in one shot, so it is granted separately.
     *
     * Per-organization scope is *not* checked here — the target organization is
     * only known once the workbook is parsed. The service re-checks
     * {@see OrganizationScopeService::canAccessOrganization()} against the
     * organization the file actually resolves to.
     */
    public function import(User $user): bool
    {
        return $user->can('organizations.import');
    }
}
