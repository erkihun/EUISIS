<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SituationAction;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SituationRoom\SituationPermissions;

/**
 * The Executive Action Register: the grant decides what a user may do, the
 * organization scope decides which organizations' actions they may do it to.
 * An action outside the scope is invisible (404 at the route, 403 here).
 */
final class SituationActionPolicy
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can(SituationPermissions::ACTIONS_VIEW);
    }

    public function view(User $user, SituationAction $action): bool
    {
        return $this->viewAny($user) && $this->scope->canAccessOrganization($user, $action->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can(SituationPermissions::ACTIONS_MANAGE);
    }

    public function update(User $user, SituationAction $action): bool
    {
        return $this->create($user) && $this->scope->canAccessOrganization($user, $action->organization_id);
    }
}
