<?php

declare(strict_types=1);

namespace App\Actions\IsicActivities;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IsicActivity;
use App\Models\User;
use Illuminate\Http\Request;

readonly class RestoreIsicActivityAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IsicActivity $activity, User $actor, ?Request $request = null): IsicActivity
    {
        $oldValues = $activity->toArray();

        $activity->restore();
        $activity->forceFill(['is_active' => true])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::IsicActivityRestored,
            $actor,
            $activity->fresh(),
            null,
            oldValues: $oldValues,
            newValues: $activity->fresh()->toArray(),
            request: $request,
        );

        return $activity->fresh();
    }
}
