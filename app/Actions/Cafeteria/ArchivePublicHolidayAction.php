<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\PublicHoliday;
use App\Models\User;
use Illuminate\Http\Request;

readonly class ArchivePublicHolidayAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(PublicHoliday $holiday, User $actor, ?string $reason = null, ?Request $request = null): PublicHoliday
    {
        $holiday->forceFill([
            'is_active'       => false,
            'deleted_by'      => $actor->id,
            'deletion_reason' => $reason,
        ])->save();

        $holiday->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::PublicHolidayArchived,
            $actor,
            $holiday,
            null,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        return $holiday;
    }
}
