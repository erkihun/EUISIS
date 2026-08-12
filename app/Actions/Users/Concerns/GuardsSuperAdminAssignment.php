<?php

declare(strict_types=1);

namespace App\Actions\Users\Concerns;

use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Validation\ValidationException;

/**
 * Prevents privilege escalation via role assignment.
 *
 *  1. Only an actor who is already a Super Admin may grant the Super Admin
 *     role — without this any delegated admin holding `users.assignRoles`
 *     could self-elevate to full platform control.
 *  2. A scoped actor (e.g. Organizational Admin — one who is not unrestricted
 *     per OrganizationScopeService) may never grant an elevated, citywide role
 *     (Super Admin / City Admin / Public Service Bureau Admin). "Full access
 *     inside scope" must never become a lever for granting global access.
 */
trait GuardsSuperAdminAssignment
{
    /**
     * @param  array<int, string>  $oldRoles  roles the target currently has
     * @param  array<int, string>  $newRoles  roles about to be assigned
     */
    protected function guardSuperAdminAssignment(User $actor, array $oldRoles, array $newRoles): void
    {
        $isBeingGranted = in_array('Super Admin', $newRoles, true)
            && ! in_array('Super Admin', $oldRoles, true);

        if ($isBeingGranted && ! $actor->hasRole('Super Admin')) {
            throw ValidationException::withMessages([
                'roles' => __('users.cannot_assign_super_admin'),
            ]);
        }

        // A scoped actor cannot newly grant any elevated citywide role.
        if (app(OrganizationScopeService::class)->isUnrestricted($actor)) {
            return;
        }

        // Roles that confer citywide/global authority.
        $elevatedRoles = ['Super Admin', 'City Admin', 'Public Service Bureau Admin'];

        foreach ($elevatedRoles as $elevated) {
            $granting = in_array($elevated, $newRoles, true) && ! in_array($elevated, $oldRoles, true);

            if ($granting) {
                throw ValidationException::withMessages([
                    'roles' => __('users.cannot_assign_role', ['role' => $elevated]),
                ]);
            }
        }
    }
}
