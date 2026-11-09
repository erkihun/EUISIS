<?php

declare(strict_types=1);

namespace App\Actions\Occupations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Occupation;
use App\Models\User;
use Illuminate\Http\Request;

readonly class RestoreOccupationAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Occupation $occupation, User $actor, ?Request $request = null): Occupation
    {
        $oldValues = $occupation->toArray();

        $occupation->restore();
        $occupation->forceFill(['is_active' => true])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordRestored,
            $actor,
            $occupation->fresh(),
            null,
            oldValues: $oldValues,
            newValues: $occupation->fresh()->toArray(),
            request: $request,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::OccupationRestored,
            $actor,
            $occupation->fresh(),
            null,
            oldValues: $oldValues,
            newValues: $occupation->fresh()->toArray(),
            request: $request,
        );

        return $occupation->fresh();
    }
}
