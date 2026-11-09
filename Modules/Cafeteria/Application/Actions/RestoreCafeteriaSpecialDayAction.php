<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Cafeteria\Domain\Models\CafeteriaSpecialDay;

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
