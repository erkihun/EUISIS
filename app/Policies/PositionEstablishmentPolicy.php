<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstablishmentStatus;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class PositionEstablishmentPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('position-establishments.viewAny');
    }

    public function view(User $user, PositionEstablishment $establishment): bool
    {
        return $user->can('position-establishments.view')
            && $this->organizationScopeService->canAccessOrganization($user, $establishment->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('position-establishments.create');
    }

    public function update(User $user, PositionEstablishment $establishment): bool
    {
        return $user->can('position-establishments.update')
            && $this->view($user, $establishment)
            && $establishment->status !== EstablishmentStatus::Archived;
    }

    public function approve(User $user, PositionEstablishment $establishment): bool
    {
        return $user->can('position-establishments.approve')
            && $this->view($user, $establishment)
            && $establishment->status === EstablishmentStatus::Draft;
    }

    public function archive(User $user, PositionEstablishment $establishment): bool
    {
        return $user->can('position-establishments.archive')
            && $this->view($user, $establishment)
            && ! $establishment->status->isFinal();
    }

    public function restore(User $user, PositionEstablishment $establishment): bool
    {
        return $user->can('position-establishments.restore')
            && $this->organizationScopeService->canAccessOrganization($user, $establishment->organization_id);
    }
}
