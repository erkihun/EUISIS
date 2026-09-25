<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Users\Concerns\GuardsSuperAdminAssignment;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Security\Passwords\PasswordLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class UpdateUserAction
{
    use GuardsSuperAdminAssignment;

    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private PasswordLifecycle $passwordLifecycle,
    ) {}

    public function execute(array $attributes, User $user, User $actor): User
    {
        return DB::transaction(function () use ($attributes, $user, $actor): User {
            $oldValues = $user->only(['name', 'email', 'status', 'phone_number', 'gender']);
            $oldRoles = $user->getRoleNames()->toArray();
            $roles = $attributes['roles'] ?? null;
            unset($attributes['roles']);

            if (($attributes['status'] ?? $user->status) !== $user->status && $actor->id === $user->id) {
                throw ValidationException::withMessages([
                    'status' => __('users.cannot_deactivate_self'),
                ]);
            }

            if (($attributes['status'] ?? $user->status) !== 'active' && $this->isLastActiveSuperAdmin($user)) {
                throw ValidationException::withMessages([
                    'status' => __('users.cannot_deactivate_last_super_admin'),
                ]);
            }

            if (is_array($roles)) {
                $this->guardSuperAdminAssignment($actor, $oldRoles, $roles);
            }

            if (is_array($roles) && in_array('Super Admin', $oldRoles, true) && ! in_array('Super Admin', $roles, true) && $this->isLastActiveSuperAdmin($user)) {
                throw ValidationException::withMessages([
                    'roles' => __('users.cannot_remove_last_super_admin'),
                ]);
            }

            $newPassword = $attributes['password'] ?? null;
            unset($attributes['password'], $attributes['generate_temporary_password']);

            $user->update($attributes);

            /*
             * An administrator setting someone's password: the central policy
             * has validated it against that account; PasswordLifecycle adds it
             * to history, forces a change at next sign-in, rotates the
             * remember token, audits (AdminPasswordReset) and notifies the
             * holder. Your OWN password is changed only in Profile, where the
             * current one is confirmed.
             */
            if (is_string($newPassword) && $newPassword !== '') {
                if ($actor->is($user)) {
                    throw ValidationException::withMessages([
                        'password' => __('password-policy.set_own_password_in_profile'),
                    ]);
                }

                $this->passwordLifecycle->change(
                    $user,
                    $newPassword,
                    AuditEventType::AdminPasswordReset,
                    PasswordLifecycle::KIND_ADMIN_RESET,
                    mustChange: true,
                    actor: $actor,
                    reason: 'administrator_reset_user_password',
                );
            }

            if (is_array($roles) && $actor->can('assignRoles', $user)) {
                $user->syncRoles($roles);

                if (collect($oldRoles)->sort()->values()->all() !== collect($roles)->sort()->values()->all()) {
                    $this->writeAuditLogAction->execute(
                        AuditEventType::PermissionChanged,
                        $actor,
                        $user,
                        null,
                        oldValues: ['roles' => $oldRoles],
                        newValues: ['roles' => $roles],
                    );
                }
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::UserUpdated,
                $actor,
                $user,
                null,
                oldValues: $oldValues,
                newValues: [
                    ...$user->fresh()->only(['name', 'email', 'status', 'phone_number', 'gender']),
                    'roles' => is_array($roles) ? $roles : $oldRoles,
                ],
            );

            return $user->fresh();
        });
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->hasRole('Super Admin')) {
            return false;
        }

        return User::role('Super Admin')
            ->where('id', '!=', $user->id)
            ->where('status', 'active')
            ->count() === 0;
    }
}
