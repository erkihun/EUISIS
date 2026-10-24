<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Backbone\Commands\EndAssignment;
use App\Backbone\Commands\StartAssignment;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmploymentStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What a change of employment status does to the employee's placement
 * (ADR 0002, decision 2). Runs in the transaction that saves the status.
 *
 *  - Employment ends (retired, terminated, deceased, or transferred out of the
 *    administration): the current assignment is closed and its seat released
 *    the same day (EndAssignment).
 *  - Employment resumes (back to active or suspended from one of those): the
 *    employee returns to their last position if it is still active and has a
 *    free seat (StartAssignment); otherwise the change is refused and HR
 *    places them through a transfer.
 *  - Any other change (draft to active, active to suspended) keeps the
 *    placement: a suspended employee still holds their position.
 *
 * Every change is also written to the employment status history, so the
 * periods an employee spent in each status are kept.
 */
final readonly class EmploymentStatusTransition
{
    /** Statuses in which the employee no longer works in the administration. */
    public const SEPARATED = [EmployeeStatus::Retired, EmployeeStatus::Terminated, EmployeeStatus::Deceased, EmployeeStatus::Transferred];

    public function __construct(
        private EndAssignment $endAssignment,
        private StartAssignment $startAssignment,
        private WriteAuditLogAction $audit,
    ) {}

    public function apply(Employee $employee, EmployeeStatus $from, EmployeeStatus $to, ?User $actor, ?Request $request = null): void
    {
        if ($from === $to) {
            return;
        }

        $today = Carbon::today();
        $this->recordHistory($employee, $to, $today, $from);

        $separating = in_array($to, self::SEPARATED, true) && ! in_array($from, self::SEPARATED, true);
        $resuming = in_array($from, self::SEPARATED, true) && in_array($to, [EmployeeStatus::Active, EmployeeStatus::Suspended], true);

        if ($separating) {
            $ended = $this->endAssignment->execute($employee, $today, 'employment_ended:'.$to->value);
            if ($ended !== null) {
                $this->audit->execute(AuditEventType::AssignmentChanged, $actor, $ended, (string) $ended->organization_id,
                    newValues: ['assignment_status' => 'closed', 'effective_to' => $today->toDateString()],
                    reason: 'Placement ended: employment status changed from '.$from->value.' to '.$to->value.'.', request: $request);
            }

            return;
        }

        if ($resuming) {
            $placement = $this->startAssignment->execute($employee, $today, 'employment_resumed:'.$to->value);
            $this->audit->execute(AuditEventType::AssignmentChanged, $actor, $placement, (string) $placement->organization_id,
                newValues: ['assignment_status' => 'active', 'effective_from' => $placement->effective_from?->toDateString()],
                reason: 'Placement resumed: employment status changed from '.$from->value.' to '.$to->value.'.', request: $request);
        }
    }

    private function recordHistory(Employee $employee, EmployeeStatus $to, Carbon $today, EmployeeStatus $from): void
    {
        $open = EmploymentStatusHistory::query()
            ->where('employee_id', $employee->getKey())
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->latest('effective_from')
            ->first();

        if ($open !== null) {
            // Periods are inclusive: the previous status ends the day before, never before it began.
            $endsOn = $today->copy()->subDay();
            $open->update(['effective_to' => $open->effective_from !== null && $endsOn->lt($open->effective_from) ? $open->effective_from : $endsOn]);
        }

        EmploymentStatusHistory::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => $to,
            'effective_from' => $today->toDateString(),
            'reason' => 'status_changed_from:'.$from->value,
        ]);
    }
}
