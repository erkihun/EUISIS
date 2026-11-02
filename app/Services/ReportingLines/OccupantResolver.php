<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OccupantBasis;
use App\Models\ActingAppointment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Services\Employees\EmployeeWorkContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Who holds which positions on a date (ADR 0025): the assignments in force
 * (any status but pending transfer, dates covering the day) and the acting
 * appointments in force. Employment status is read as of the day through the
 * status history, so a past date is not judged by today's status.
 *
 * Bulk by design: one query for assignments, one for acting appointments,
 * one for the employees, whatever the number of positions asked for.
 */
class OccupantResolver
{
    public function __construct(private readonly EmployeeWorkContextResolver $workContext) {}

    /**
     * @param  list<string>  $positionIds
     * @return array<string, list<Occupant>> position id => occupants
     */
    public function occupantsOf(array $positionIds, Carbon|string|null $asOf = null): array
    {
        $positionIds = array_values(array_unique(array_map('strval', $positionIds)));
        if ($positionIds === []) {
            return [];
        }
        $day = ReportingGraphRepository::day($asOf);

        $assignments = $this->assignmentsOn($positionIds, $day)
            ->get(['id', 'employee_id', 'position_id']);
        $acting = ActingAppointment::query()
            ->whereIn('position_id', $positionIds)
            ->inForceOn($day)
            ->get(['id', 'employee_id', 'position_id']);

        $employeeIds = $assignments->pluck('employee_id')->merge($acting->pluck('employee_id'))->map(fn ($id): string => (string) $id)->unique()->values()->all();
        if ($employeeIds === []) {
            return [];
        }
        $employees = Employee::query()->whereIn('id', $employeeIds)->get(['id', 'employee_number', 'full_name', 'first_name', 'last_name', 'status', 'metadata'])->keyBy('id');
        $histories = $day === today()->toDateString() ? collect() : $this->workContext->statusHistoriesFor($employeeIds, Carbon::parse($day), Carbon::parse($day));

        $result = [];
        foreach ($acting as $row) {
            $employee = $employees->get((string) $row->employee_id);
            if ($employee === null) {
                continue;
            }
            $result[(string) $row->position_id][] = $this->occupant($employee, (string) $row->position_id, OccupantBasis::Acting, $day, $histories, actingId: (string) $row->id);
        }
        foreach ($assignments as $row) {
            $employee = $employees->get((string) $row->employee_id);
            if ($employee === null) {
                continue;
            }
            $result[(string) $row->position_id][] = $this->occupant($employee, (string) $row->position_id, OccupantBasis::Substantive, $day, $histories, assignmentId: (string) $row->id);
        }

        return $result;
    }

    /**
     * Assignments placing people in the positions on the day. Pending
     * transfers are not yet a place of work; closed rows still describe the
     * past up to their end date.
     *
     * @param  list<string>  $positionIds
     * @return Builder<EmployeeAssignment>
     */
    public function assignmentsOn(array $positionIds, string $day): Builder
    {
        return EmployeeAssignment::query()
            ->whereIn('position_id', $positionIds)
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day));
    }

    /**
     * The acting and substantive position ids an employee holds on the day.
     *
     * @return array{substantive: list<string>, acting: list<string>}
     */
    public function positionsHeldBy(string $employeeId, Carbon|string|null $asOf = null): array
    {
        $day = ReportingGraphRepository::day($asOf);

        $substantive = EmployeeAssignment::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('position_id')
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->pluck('position_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
        $acting = ActingAppointment::query()
            ->where('employee_id', $employeeId)
            ->inForceOn($day)
            ->pluck('position_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();

        return ['substantive' => $substantive, 'acting' => $acting];
    }

    /** @param \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\EmploymentStatusHistory>> $histories */
    private function occupant(Employee $employee, string $positionId, OccupantBasis $basis, string $day, $histories, ?string $assignmentId = null, ?string $actingId = null): Occupant
    {
        $status = $day === today()->toDateString()
            ? ($employee->status instanceof EmployeeStatus ? $employee->status : (EmployeeStatus::tryFrom((string) $employee->status) ?? EmployeeStatus::Draft))
            : $this->workContext->statusOn($employee, $histories->get((string) $employee->id, collect()), $day);

        return new Occupant(
            positionId: $positionId,
            employeeId: (string) $employee->id,
            basis: $basis,
            employeeNumber: $employee->employee_number,
            name: $employee->full_name ?: trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
            nameAm: $employee->fullNameAm(),
            employeeActive: $status === EmployeeStatus::Active,
            assignmentId: $assignmentId,
            actingAppointmentId: $actingId,
        );
    }
}
