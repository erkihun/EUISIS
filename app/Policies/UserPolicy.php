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

        return $actor->can('users.view') && $this->sharesScope($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    public function update(User $actor, User $target): bool
    {
        // A non-Super-Admin must never be able to edit a Super Admin account
        // (email/password change = account takeover). Editing one's own
        // profile is handled separately via ProfileController.
        if ($target->hasRole('Super Admin') && $actor->id !== $target->id && ! $actor->hasRole('Super Admin')) {
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

        return $actor->can('users.archive') && $this->sharesScope($actor, $target);
    }

    public function restore(User $actor, User $target): bool
    {
        return $actor->can('users.restore') && $this->sharesScope($actor, $target);
    }

    public function assignRoles(User $actor, User $target): bool
    {
        return $actor->can('users.assignRoles') && $this->sharesScope($actor, $target);
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
        if ($this->organizationScopeService->isUnrestricted($actor)) {
            return true;
        }

        $allowed = $this->organizationScopeService->allowedOrganizationIds($actor);

        if ($target->default_organization_id !== null
            && in_array((string) $target->default_organization_id, $allowed, true)) {
            return true;
        }

        return $target->organizationScopes()
            ->whereIn('organization_id', $allowed)
            ->exists();
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
