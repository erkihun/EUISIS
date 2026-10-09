<?php

declare(strict_types=1);

namespace App\Actions\Occupations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Occupation;
use App\Models\User;
use Illuminate\Http\Request;

readonly class ArchiveOccupationAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Occupation $occupation, User $actor, ?string $reason = null, ?Request $request = null): Occupation
    {
        $oldValues = $occupation->toArray();

        $occupation->forceFill(['is_active' => false])->save();
        $occupation->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordDeleted,
            $actor,
            $occupation,
            null,
            oldValues: $oldValues,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::OccupationArchived,
            $actor,
            $occupation,
            null,
            oldValues: $oldValues,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        return $occupation;
    }
}
