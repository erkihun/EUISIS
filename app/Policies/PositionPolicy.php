<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Position;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class PositionPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('positions.viewAny');
    }

    public function view(User $user, Position $position): bool
    {
        return $user->can('positions.view')
            && (
                $position->organization_id === null
                || $this->organizationScopeService->canAccessOrganization($user, $position->organization_id)
            );
    }

    public function create(User $user): bool
    {
        return $user->can('positions.create');
    }

    public function update(User $user, Position $position): bool
    {
        return $user->can('positions.update') && $this->view($user, $position);
    }

    public function move(User $user, Position $position): bool
    {
        return $user->can('positions.move')
            && $position->is_active
            && $position->organization_id !== null
            && $position->organization_unit_id !== null
            && $this->view($user, $position);
    }

    public function archive(User $user, Position $position): bool
    {
        return ($user->can('positions.delete') || $user->can('positions.archive')) && $this->view($user, $position);
    }

    public function restore(User $user, Position $position): bool
    {
        return $user->can('positions.restore') && $this->view($user, $position);
    }

    public function viewDeleted(User $user): bool
    {
        return $user->can('positions.viewDeleted');
    }
}
