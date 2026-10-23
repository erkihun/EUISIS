<?php

declare(strict_types=1);

namespace App\Backbone\Commands;

use App\Enums\AssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Services\Integration\Events\TransferEvents;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The backbone command that moves an employee from one assignment to the next.
 *
 * It closes the current assignment the day before the effective date, opens the
 * new current assignment on that date and moves the employee's current pointer.
 * The employee record, its UUID and employee number are never changed here, and
 * the employee's card is not touched. Business processes (announcement transfer,
 * vacancy transfer) call it inside their own transaction and keep their own
 * capacity, agreement and event steps.
 */
final readonly class TransferAssignment
{
    public function __construct(private TransferEvents $events) {}

    /**
     * @param  array{organization_id: ?string, organization_unit_id: ?string, position_id: ?string, hierarchy_version_id: ?string, job_description_id: ?string}  $placement
     */
    public function execute(
        Employee $employee,
        EmployeeAssignment $current,
        array $placement,
        CarbonInterface $effectiveDate,
        string $closeReason,
        string $openReason,
    ): EmployeeAssignment {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TransferAssignment must run inside the caller\'s transaction.');
        }
        if ((string) $current->employee_id !== (string) $employee->getKey()) {
            throw new LogicException('The assignment to close does not belong to this employee.');
        }
        if (! $current->is_current) {
            throw new LogicException('The assignment to close is no longer the employee\'s current assignment.');
        }
        // The closed assignment ends the day before the new one starts; a date on or
        // before its own start would leave it with an end before its beginning.
        if ($current->effective_from !== null && $effectiveDate->toDateString() <= $current->effective_from->toDateString()) {
            throw ValidationException::withMessages(['effective_date' => __('backbone.transfer_effective_date_after_current', ['date' => $current->effective_from->toDateString()])]);
        }

        $current->update([
            'assignment_status' => AssignmentStatus::Closed,
            'is_current' => false,
            // Intervals are inclusive: the successor starts on its effective date.
            'effective_to' => $effectiveDate->copy()->subDay()->toDateString(),
            'reason' => $closeReason,
        ]);

        $next = EmployeeAssignment::query()->create([
            'employee_id' => $employee->getKey(),
            'organization_id' => $placement['organization_id'],
            'organization_unit_id' => $placement['organization_unit_id'],
            'position_id' => $placement['position_id'],
            'hierarchy_version_id' => $placement['hierarchy_version_id'],
            'job_description_id' => $placement['job_description_id'],
            'assignment_status' => AssignmentStatus::Active,
            'effective_from' => $effectiveDate->toDateString(),
            'effective_to' => null,
            'is_current' => true,
            'reason' => $openReason,
        ]);

        $this->events->movePointer($employee, fn () => $employee->update(['current_assignment_id' => $next->getKey()]));

        return $next;
    }
}
