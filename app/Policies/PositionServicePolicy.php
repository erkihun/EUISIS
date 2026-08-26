<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Position;
use App\Models\PositionService;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Access control for feedback services attached to positions.
 *
 * Deliberately separate from ServiceTypePolicy: that one guards the shared
 * platform catalog (`/service-types`), which providers, entitlements and
 * transactions depend on. This one guards the per-position feedback services,
 * and is scoped through the position's organization so an Organizational Admin
 * manages only the services their own posts provide.
 */
class PositionServicePolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.settings.manage', 'service_feedback.view']);
    }

    public function view(User $user, PositionService $record): bool
    {
        return $this->viewAny($user) && $this->withinScope($user, $record->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.settings.manage']);
    }

    /**
     * May this user attach a service to THIS position?
     *
     * Checked separately from create() because the position arrives in the
     * request body: holding the permission is not enough if the position
     * belongs to an organization outside the actor's scope.
     */
    public function createForPosition(User $user, Position $position): bool
    {
        return $this->create($user) && $this->withinScope($user, $position->organization_id);
    }

    public function update(User $user, PositionService $record): bool
    {
        return $user->hasAnyPermission(['service_feedback.settings.manage'])
            && $this->withinScope($user, $record->organization_id);
    }

    public function delete(User $user, PositionService $record): bool
    {
        return $this->update($user, $record);
    }

    /** Toggling whether ratings for this service count toward evaluation. */
    public function managePerformanceFlag(User $user, PositionService $record): bool
    {
        return $user->hasAnyPermission(['service_feedback.settings.manage'])
            && $this->withinScope($user, $record->organization_id);
    }

    /**
     * Renumber a service that already carries client feedback.
     *
     * Deliberately its OWN permission rather than the general manage right:
     * the Service No is quoted in stored feedback and performance reports, so
     * changing it after ratings exist rewrites how history reads. Ordinary
     * administrators may edit everything else about the service; only a holder
     * of this elevated right may renumber a rated one.
     */
    public function renumberAfterFeedback(User $user, PositionService $record): bool
    {
        return $user->hasAnyPermission(['service_feedback.service_no.override'])
            && $this->withinScope($user, $record->organization_id);
    }

    /**
     * Organization scope, resolved through the position that owns the service.
     *
     * A position with no organization on file is visible only to unrestricted
     * roles: there is nothing to scope it against, so a scoped administrator
     * must not inherit it.
     */
    private function withinScope(User $user, ?string $organizationId): bool
    {
        if ($this->scope->isUnrestricted($user)) {
            return true;
        }

        if ($organizationId === null) {
            return false;
        }

        return $this->scope->canAccessOrganization($user, $organizationId);
    }
}
