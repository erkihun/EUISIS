<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Cafeteria\Domain\Models\CafeteriaSpecialDay;

readonly class ArchiveCafeteriaSpecialDayAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaSpecialDay $day, User $actor, ?string $reason, ?Request $request = null): void
    {
        $day->update([
            'is_active' => false,
            'deleted_by' => $actor->id,
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
