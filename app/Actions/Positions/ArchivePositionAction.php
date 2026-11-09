<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

readonly class ArchivePositionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Position $position, User $actor, ?string $reason = null, ?Request $request = null): Position
    {
        // Master backbone audit: a position holding a live assignment used to
        // be archived underneath its occupant, leaving the employee placed on
        // a seat that no longer exists. The occupant must be moved first.
        $occupants = EmployeeAssignment::query()
            ->where('position_id', $position->getKey())
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->count();

        if ($occupants > 0) {
            throw ValidationException::withMessages([
                'position' => __('positions.archive_blocked_active_assignments', ['count' => $occupants]),
            ]);
        }

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
