<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaSpecialDay;
use App\Models\User;
use Illuminate\Http\Request;

readonly class ArchiveCafeteriaSpecialDayAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaSpecialDay $day, User $actor, ?string $reason, ?Request $request = null): void
    {
        $day->update([
            'is_active'       => false,
            'deleted_by'      => $actor->id,
            'deletion_reason' => $reason,
        ]);
        $day->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaSpecialDayArchived,
            $actor,
            $day,
            $day->organization_id,
            newValues: ['deletion_reason' => $reason],
            request: $request,
        );
    }
}
