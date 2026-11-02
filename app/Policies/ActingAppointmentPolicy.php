<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActingAppointment;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Acting appointments are an HR act (ADR 0025): reporting-lines.manage within
 * the organization scope creates and revokes them; reporting-lines.view_any
 * reads them. A supervisor cannot appoint their own stand-in.
 */
readonly class ActingAppointmentPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('reporting-lines.view_any') || $user->can('reporting-lines.manage');
    }

    public function view(User $user, ActingAppointment $appointment): bool
    {
        return $this->viewAny($user) && $this->scope->canAccessOrganization($user, (string) $appointment->organization_id);
    }

    public function create(User $user, Organization|string|null $organization = null): bool
    {
        if (! $user->can('reporting-lines.manage')) {
            return false;
        }
        $organizationId = $organization instanceof Organization ? (string) $organization->getKey() : $organization;

        return $organizationId === null ? true : $this->scope->canAccessOrganization($user, $organizationId);
    }

    public function revoke(User $user, ActingAppointment $appointment): bool
    {
        return $user->can('reporting-lines.manage')
            && $this->scope->canAccessOrganization($user, (string) $appointment->organization_id);
    }
}
