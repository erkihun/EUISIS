<?php

declare(strict_types=1);

namespace App\Actions\GradeLevels;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\GradeLevel;
use App\Models\User;
use Illuminate\Http\Request;

readonly class ArchiveGradeLevelAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(GradeLevel $gradeLevel, User $actor, ?string $reason = null, ?Request $request = null): GradeLevel
    {
        $oldValues = $gradeLevel->toArray();

        $gradeLevel->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordDeleted,
            $actor,
            $gradeLevel,
            null,
            oldValues: $oldValues,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::GradeLevelArchived,
            $actor,
            $gradeLevel,
            null,
            oldValues: $oldValues,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        return $gradeLevel;
    }
}
