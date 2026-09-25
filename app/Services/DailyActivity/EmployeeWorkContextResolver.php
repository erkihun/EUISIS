<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmploymentStatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where did an employee work, and were they employed, on a given date?
 *
 * Answers from the assignment and employment-status history, never from the
 * employee's current placement, so an activity recorded before a transfer
 * keeps the organization, unit and position that applied at the time.
 *
 * Bulk methods load once per report so a month for a whole organization is
 * two queries, not one per employee-day.
 */
class EmployeeWorkContextResolver
{
    public function assignmentOn(Employee $employee, Carbon $date): ?EmployeeAssignment
    {
        $assignments = $this->assignmentsFor([$employee->id], $date, $date)->get($employee->id, collect());

        return $this->pickAssignment($assignments, $date->toDateString());
    }

    public function employmentStatusOn(Employee $employee, Carbon $date): EmployeeStatus
    {
        $histories = $this->statusHistoriesFor([$employee->id], $date, $date)->get($employee->id, collect());

        return $this->statusOn($employee, $histories, $date->toDateString());
    }

    public function isEmployedOn(Employee $employee, Carbon $date): bool
    {
        return $this->employmentStatusOn($employee, $date) === EmployeeStatus::Active;
    }

    /**
     * Assignments overlapping [from, to], grouped by employee id.
     *
     * A pending-transfer assignment is not yet a place of work, so it is
     * excluded; closed assignments stay because they describe the past.
     *
     * @param  array<int, string>  $employeeIds
     * @return Collection<string, Collection<int, EmployeeAssignment>>
     */
    public function assignmentsFor(array $employeeIds, Carbon $from, Carbon $to): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return EmployeeAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->whereDate('effective_from', '<=', $to->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $from->toDateString()))
            ->get(['id', 'employee_id', 'organization_id', 'organization_unit_id', 'position_id', 'effective_from', 'effective_to', 'is_current', 'assignment_status'])
            ->groupBy('employee_id');
    }

    /** @param Collection<int, EmployeeAssignment> $assignments */
    public function pickAssignment(Collection $assignments, string $date): ?EmployeeAssignment
    {
        return $assignments
            ->filter(fn (EmployeeAssignment $assignment): bool => $assignment->effective_from->toDateString() <= $date
                && ($assignment->effective_to === null || $assignment->effective_to->toDateString() >= $date))
            ->sortByDesc(fn (EmployeeAssignment $assignment): string => ($assignment->is_current ? '1' : '0').$assignment->effective_from->toDateString())
            ->first();
    }

    /**
     * @param  array<int, string>  $employeeIds
     * @return Collection<string, Collection<int, EmploymentStatusHistory>>
     */
    public function statusHistoriesFor(array $employeeIds, Carbon $from, Carbon $to): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return EmploymentStatusHistory::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $to->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $from->toDateString()))
            ->get(['employee_id', 'status', 'effective_from', 'effective_to'])
            ->groupBy('employee_id');
    }

    /**
     * Status history wins when it covers the date; otherwise the employee's
     * current status is the best available answer.
     *
     * @param  Collection<int, EmploymentStatusHistory>  $histories
     */
    public function statusOn(Employee $employee, Collection $histories, string $date): EmployeeStatus
    {
        $covering = $histories
            ->filter(fn (EmploymentStatusHistory $history): bool => $history->effective_from->toDateString() <= $date
                && ($history->effective_to === null || $history->effective_to->toDateString() >= $date))
            ->sortByDesc(fn (EmploymentStatusHistory $history): string => $history->effective_from->toDateString())
            ->first();

        if ($covering !== null) {
            return $covering->status;
        }

        return $employee->status instanceof EmployeeStatus
            ? $employee->status
            : (EmployeeStatus::tryFrom((string) $employee->status) ?? EmployeeStatus::Draft);
    }
}
