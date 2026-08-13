<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class UserPolicy
{
    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can('users.viewAny');
    }

    public function view(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        if ($this->isProtectedUser($target) && ! $this->isGlobalAdministrator($actor)) {
            return false;
        }

        return $actor->can('users.view') && $this->sharesScope($actor, $target);
    }

    public function create(User $actor): bool
    {
        if (! $actor->can('users.create')) {
            return false;
        }

        return ! $this->organizationScopeService->isScopedOrganizationalAdmin($actor)
            || $this->organizationScopeService->assignedOrganizationIds($actor) !== [];
    }

    public function update(User $actor, User $target): bool
    {
        // A non-Super-Admin must never be able to edit a Super Admin account
        // (email/password change = account takeover). Editing one's own
        // profile is handled separately via ProfileController.
        if ($this->isProtectedUser($target) && ! $this->isGlobalAdministrator($actor)) {
            return false;
        }

        return $actor->can('users.update') && $this->sharesScope($actor, $target);
    }

    public function delete(User $actor, User $model): bool
    {
        if ($actor->id === $model->id) {
            return false;
        }

        if ($this->isLastActiveSuperAdmin($model)) {
            return false;
        }

        if ($this->isProtectedUser($model) && ! $this->isGlobalAdministrator($actor)) {
            return false;
        }

        return $actor->can('users.delete') && $this->sharesScope($actor, $model);
    }

    public function deactivate(User $actor, User $model): bool
    {
        if ($actor->id === $model->id) {
            return false;
        }

        if ($this->isLastActiveSuperAdmin($model)) {
            return false;
        }

        if ($this->isProtectedUser($model) && ! $this->isGlobalAdministrator($actor)) {
            return false;
        }

        return $actor->can('users.deactivate') && $this->sharesScope($actor, $model);
    }

    public function archive(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        if ($this->isLastActiveSuperAdmin($target)) {
            return false;
        }

        if ($this->isProtectedUser($target) && ! $this->isGlobalAdministrator($actor)) {
            return false;
        }

        return $actor->can('users.archive') && $this->sharesScope($actor, $target);
    }

    public function restore(User $actor, User $target): bool
    {
        if ($this->isProtectedUser($target) && ! $this->isGlobalAdministrator($actor)) {
            return false;
        }

        return $actor->can('users.restore') && $this->sharesScope($actor, $target);
    }

    public function assignRoles(User $actor, User $target): bool
    {
        if ($actor->id === $target->id || ($this->isProtectedUser($target) && ! $this->isGlobalAdministrator($actor))) {
            return false;
        }

        return $actor->can('users.assignRoles') && $this->sharesScope($actor, $target);
    }

    public function assignOrganizationScope(User $actor, User $target): bool
    {
        if ($actor->id === $target->id || ! $actor->can('users.assignOrganizationScopes')) {
            return false;
        }

        if ($this->isProtectedUser($target) && ! $this->isGlobalAdministrator($actor)) {
            return false;
        }

        if ($this->organizationScopeService->isUnrestricted($actor)) {
            return true;
        }

        $hasOrganizationLink = $target->default_organization_id !== null
            || $target->organizationScopes()->exists();

        return ! $hasOrganizationLink || $this->sharesScope($actor, $target);
    }

    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->can('users.resetPassword') && $this->sharesScope($actor, $target);
    }

    /**
     * True when the target user is within the actor's organization scope.
     *
     * Unrestricted actors (Super Admin / City Admin / unscoped staff) always
     * pass. A scoped actor (e.g. Organizational Admin) may only reach a target
     * that shares one of the actor's accessible organizations — via the
     * target's own scope records or their default organization. A target with
     * no organization linkage at all is treated as out-of-scope for a scoped
     * actor (they belong to the citywide pool a scoped admin must not manage).
     */
    private function sharesScope(User $actor, User $target): bool
    {
        return $this->organizationScopeService->canManageUser($actor, $target);
    }

    private function isProtectedUser(User $user): bool
    {
        return $user->hasAnyRole(['Super Admin', 'System Admin', 'City Admin', 'Public Service Bureau Admin']);
    }

    private function isGlobalAdministrator(User $user): bool
    {
        return $user->hasAnyRole(['Super Admin', 'System Admin', 'City Admin']);
    }

    private function isLastActiveSuperAdmin(User $model): bool
    {
        if (! $model->hasRole('Super Admin')) {
            return false;
        }

        return User::role('Super Admin')
            ->where('id', '!=', $model->id)
            ->where('status', 'active')
            ->count() === 0;
    }
}
