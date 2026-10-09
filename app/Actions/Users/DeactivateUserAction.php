<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class DeactivateUserAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            if ($actor->id === $user->id) {
                $this->writeAuditLogAction->execute(
                    AuditEventType::UserDeactivationBlockedSelf,
                    $actor,
                    $user,
                    null,
                    newValues: ['reason' => 'self_deactivation_blocked'],
                );

                throw ValidationException::withMessages([
                    'user' => __('users.cannot_deactivate_self'),
                ]);
            }

            if ($this->isLastActiveSuperAdmin($user)) {
                $this->writeAuditLogAction->execute(
                    AuditEventType::UserDeactivationBlockedLastSuperAdmin,
                    $actor,
                    $user,
                    null,
                    newValues: ['reason' => 'last_super_admin_blocked'],
                );

                throw ValidationException::withMessages([
                    'user' => __('users.cannot_deactivate_last_super_admin'),
                ]);
            }

            $user->update(['status' => 'inactive']);

            $this->writeAuditLogAction->execute(
                AuditEventType::UserDeactivated,
                $actor,
                $user,
                null,
                oldValues: ['status' => 'active'],
                newValues: ['status' => 'inactive'],
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
