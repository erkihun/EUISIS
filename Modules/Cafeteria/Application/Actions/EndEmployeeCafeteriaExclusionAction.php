<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cafeteria\Application\Services\CafeteriaEmployeeContext;
use Modules\Cafeteria\Domain\Enums\CafeteriaExclusionStatus;
use Modules\Cafeteria\Domain\Models\EmployeeCafeteriaExclusion;

readonly class EndEmployeeCafeteriaExclusionAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        // The employee's organization comes from the Core placement, not from the Employee model.
        private CafeteriaEmployeeContext $employees,
    ) {}

    public function execute(EmployeeCafeteriaExclusion $exclusion, User $actor, ?string $returnToWorkOn, ?Request $request = null): void
    {
        $exclusion->update([
            'status' => CafeteriaExclusionStatus::Ended->value,
            'ended_by' => $actor->id,
            'ended_at' => Carbon::now(),
            'return_to_work_on' => $returnToWorkOn ?? $exclusion->return_to_work_on,
            'updated_by' => $actor->id,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::EmployeeCafeteriaExclusionEnded,
            $actor,
            $exclusion,
            $this->employees->organizationOf($exclusion->employee_id),
            newValues: ['status' => 'ended', 'return_to_work_on' => $returnToWorkOn],
            request: $request,
        );
    }
}
