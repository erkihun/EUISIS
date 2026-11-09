<?php

declare(strict_types=1);

namespace App\Backbone\Commands;

use App\Enums\AssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Services\Positions\PositionCapacityService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Ends an employee's placement without moving them anywhere: the employment
 * itself ended (retirement, termination, death, leaving the administration).
 * ADR 0002, decision 2.
 *
 * The assignment is closed on the end date and its position seat is released
 * that day, so the position counts as vacant. It stays the employee's latest
 * (`is_current`) record and their current pointer keeps naming it: their
 * organization is still known for scope and history, and the closed status
 * says the placement is over. Nothing is deleted or overwritten except the
 * status, end date and reason of this one assignment.
 *
 * A move to another placement is TransferAssignment, never this command.
 */
final readonly class EndAssignment
{
    public function __construct(private PositionCapacityService $capacity) {}

    /** @return EmployeeAssignment|null the closed assignment, or null when there was no open one */
    public function execute(Employee $employee, CarbonInterface $endDate, string $reason): ?EmployeeAssignment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EndAssignment must run inside the caller\'s transaction.');
        }

        $current = EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_current', true)
            ->lockForUpdate()
            ->first();

        // Nothing open to end: no placement, or it already ended.
        if ($current === null || $current->assignment_status === AssignmentStatus::Closed) {
            return null;
        }

        if ($current->effective_from !== null && $endDate->toDateString() < $current->effective_from->toDateString()) {
            throw ValidationException::withMessages(['status' => __('backbone.assignment_end_before_start', ['date' => $current->effective_from->toDateString()])]);
        }

        $current->update([
            'assignment_status' => AssignmentStatus::Closed,
            'effective_to' => $endDate->toDateString(),
            'reason' => $reason,
        ]);

        $this->capacity->releaseOccupancyForAssignment((string) $current->getKey(), $reason, Carbon::instance($endDate));

        return $current;
    }
}
