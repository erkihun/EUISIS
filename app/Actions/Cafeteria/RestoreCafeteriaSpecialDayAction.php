<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaSpecialDay;
use App\Models\User;
use Illuminate\Http\Request;

readonly class RestoreCafeteriaSpecialDayAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaSpecialDay $day, User $actor, ?Request $request = null): void
    {
        $day->restore();
        $day->update(['is_active' => true, 'deleted_by' => null, 'deletion_reason' => null]);

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaSpecialDayRestored,
            $actor,
            $day,
            $day->organization_id,
            request: $request,
        );
    }
}
