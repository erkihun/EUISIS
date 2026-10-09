<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\Request;

readonly class ArchivePositionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Position $position, User $actor, ?string $reason = null, ?Request $request = null): Position
    {
        $oldValues = $position->toArray();

        $position->forceFill([
            'is_active' => false,
            'deleted_by' => $actor->getKey(),
            'deletion_reason' => $reason,
        ])->save();

        $position->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordDeleted,
            $actor,
            $position,
            $position->organization_id,
            oldValues: $oldValues,
            newValues: ['deleted_at' => now()->toISOString(), 'deleted_by' => $actor->getKey(), 'deletion_reason' => $reason],
            reason: $reason,
            request: $request,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::PositionArchived,
            $actor,
            $position,
            $position->organization_id,
            oldValues: $oldValues,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        return $position;
    }
}
