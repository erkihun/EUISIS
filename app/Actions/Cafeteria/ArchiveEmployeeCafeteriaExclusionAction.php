<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\User;
use Illuminate\Http\Request;

readonly class ArchiveEmployeeCafeteriaExclusionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(EmployeeCafeteriaExclusion $exclusion, User $actor, ?string $reason, ?Request $request = null): void
    {
        $exclusion->update([
            'deleted_by'      => $actor->id,
            'deletion_reason' => $reason,
        ]);
        $exclusion->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::EmployeeCafeteriaExclusionArchived,
            $actor,
            $exclusion,
            $exclusion->employee?->organization_id,
            newValues: ['deletion_reason' => $reason],
            request: $request,
        );
    }
}
