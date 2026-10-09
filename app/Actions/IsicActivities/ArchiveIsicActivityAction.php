<?php

declare(strict_types=1);

namespace App\Actions\IsicActivities;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IsicActivity;
use App\Models\User;
use Illuminate\Http\Request;

readonly class ArchiveIsicActivityAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IsicActivity $activity, User $actor, ?string $reason = null, ?Request $request = null): IsicActivity
    {
        $oldValues = $activity->toArray();

        $activity->forceFill(['is_active' => false])->save();
        $activity->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::IsicActivityArchived,
            $actor,
            $activity,
            null,
            oldValues: $oldValues,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        return $activity;
    }
}
