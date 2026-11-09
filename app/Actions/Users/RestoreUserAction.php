<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class RestoreUserAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            $user->update(['status' => 'active']);

            $this->writeAuditLogAction->execute(
                AuditEventType::UserRestored,
                $actor,
                $user,
                null,
                oldValues: ['status' => 'inactive'],
                newValues: ['status' => 'active'],
            );

            return $user->fresh();
        });
    }
}
