<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\Request;

readonly class RestorePositionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Position $position, User $actor, ?Request $request = null): Position
    {
        $oldValues = $position->toArray();

        $position->restore();
        $position->forceFill([
            'is_active' => true,
            'deleted_by' => null,
            'deletion_reason' => null,
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordRestored,
            $actor,
            $position->fresh(),
            $position->organization_id,
            oldValues: $oldValues,
            newValues: $position->fresh()->toArray(),
            request: $request,
        );

        return $position->fresh();
    }
}
