<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaEmployeeContext;
use Illuminate\Http\Request;

readonly class ArchiveEmployeeCafeteriaExclusionAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        // The employee's organization comes from the Core placement, not from the Employee model.
        private CafeteriaEmployeeContext $employees,
    ) {}

    public function execute(EmployeeCafeteriaExclusion $exclusion, User $actor, ?string $reason, ?Request $request = null): void
    {
        $exclusion->update([
            'deleted_by' => $actor->id,
            'deletion_reason' => $reason,
        ]);
        $exclusion->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::EmployeeCafeteriaExclusionArchived,
            $actor,
            $exclusion,
            $this->employees->organizationOf($exclusion->employee_id),
            newValues: ['deletion_reason' => $reason],
            request: $request,
        );
    }
}
