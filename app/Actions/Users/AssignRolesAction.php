<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Users\Concerns\GuardsSuperAdminAssignment;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class AssignRolesAction
{
    use GuardsSuperAdminAssignment;

    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(User $user, array $roles, User $actor): User
    {
        return DB::transaction(function () use ($user, $roles, $actor): User {
            $oldRoles = $user->getRoleNames()->toArray();

            $this->guardSuperAdminAssignment($actor, $oldRoles, $roles);

            if (in_array('Super Admin', $oldRoles, true) && ! in_array('Super Admin', $roles, true) && $this->isLastActiveSuperAdmin($user)) {
                throw ValidationException::withMessages([
                    'roles' => __('users.cannot_remove_last_super_admin'),
                ]);
            }

            $user->syncRoles($roles);

            $this->writeAuditLogAction->execute(
                AuditEventType::PermissionChanged,
                $actor,
                $user,
                null,
                oldValues: ['roles' => $oldRoles],
                newValues: ['roles' => $roles],
            );

            return $user->fresh();
        });
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return User::role('Super Admin')
            ->where('id', '!=', $user->id)
            ->where('status', 'active')
            ->count() === 0;
    }
}
