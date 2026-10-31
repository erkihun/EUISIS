<?php

namespace Modules\Court\Application\Policies;

use Modules\Court\Domain\Models\CourtStaffMember as User;

class UserPolicy
{
    /**
     * Global bypass: admins can do everything.
     * Return true to allow, false to deny, or null to defer to the specific ability.
     */
    public function before(User $actor): ?bool
    {
        // If you have an 'admin' role, allow all
        return $actor->hasRole('admin') ? true : null;
    }

    /** View list of users */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view') || $actor->hasPermission('users.manage');
    }

    /** View a specific user's profile */
    public function view(User $actor, User $target): bool
    {
        return $actor->id === $target->id
            || $actor->hasPermission('users.view')
            || $actor->hasPermission('users.manage');
    }

    /** Create users */
    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.manage');
    }

    /** Update a user */
    public function update(User $actor, User $target): bool
    {
        // Allow self-edit
        if ($actor->id === $target->id) return true;

        if (! $actor->hasPermission('users.manage')) return false;

        // Only admins can modify other admins (optional guardrail)
        $targetIsAdmin = $target->roles()->where('name', 'admin')->exists();
        $actorIsAdmin  = $actor->hasRole('admin');

        return ! $targetIsAdmin || $actorIsAdmin;
    }

    /** Delete a user */
    public function delete(User $actor, User $target): bool
    {
        // Never allow self-delete
        if ($actor->id === $target->id) return false;

        if (! $actor->hasPermission('users.manage')) return false;

        // Same guardrail as update(): only admins can remove admin accounts
        // (admins pass through before()).
        return ! $target->roles()->whereRaw('LOWER(name) = ?', ['admin'])->exists();
    }

    /** Restore soft-deleted users (if you enable soft deletes) */
    public function restore(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.manage');
    }

    /** Permanently delete users (use sparingly) */
    public function forceDelete(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.manage');
    }
}
