<?php

declare(strict_types=1);

namespace App\Actions\GradeLevels;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\GradeLevel;
use App\Models\User;
use Illuminate\Http\Request;

readonly class RestoreGradeLevelAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(GradeLevel $gradeLevel, User $actor, ?Request $request = null): GradeLevel
    {
        $oldValues = $gradeLevel->toArray();

        $gradeLevel->restore();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordRestored,
            $actor,
            $gradeLevel->fresh(),
            null,
            oldValues: $oldValues,
            newValues: $gradeLevel->fresh()->toArray(),
            request: $request,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::GradeLevelRestored,
            $actor,
            $gradeLevel->fresh(),
            null,
            oldValues: $oldValues,
            newValues: $gradeLevel->fresh()->toArray(),
            request: $request,
        );

        return $gradeLevel->fresh();
    }
}
