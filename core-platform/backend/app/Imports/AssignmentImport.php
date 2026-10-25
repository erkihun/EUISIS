<?php

declare(strict_types=1);

namespace App\Imports;

use App\Actions\TransferEmployee;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;

/**
 * Placement changes (ADR 0009) through TransferEmployee: an active employee
 * with an active placement moves to another position with a free seat, both
 * inside the scope, from a date after the current placement began. One move
 * per employee per file; seats are counted across the file.
 */
final class AssignmentImport extends ImportDefinition
{
    public function __construct(OrganizationScope $scope, private readonly SeatCapacity $seats)
    {
        parent::__construct($scope);
    }

    public function columns(): array
    {
        return ['employee_number', 'position_code', 'effective_from', 'reason'];
    }

    public function permission(): string
    {
        return 'assignments.change';
    }

    public function validate(array $row, int $number, User $uploader): array
    {
        $errors = $this->basic($row, [
            'employee_number' => ['required'], 'position_code' => ['required'], 'effective_from' => ['required', 'date'], 'reason' => ['nullable', 'max:1000'],
        ]);
        $employee = $row['employee_number'] === null ? null : Employee::query()->where('employee_number', $row['employee_number'])->first();
        $current = $employee === null ? null : EmployeeAssignment::query()->find($employee->current_assignment_id);
        if ($row['employee_number'] !== null && $employee === null) {
            $errors[] = $this->error('employee_number', 'UNKNOWN_EMPLOYEE', 'No employee has this number.');
        } elseif ($employee !== null) {
            if (! $this->inScope($uploader, $current?->organization_id === null ? null : (string) $current->organization_id)) {
                $errors[] = $this->error('employee_number', 'OUT_OF_SCOPE_ORGANIZATION', 'This employee is outside your organizations.');
            } elseif ($employee->isSeparated() || ! $employee->isApproved() || $current === null || ! $current->is_current || $current->assignment_status !== EmployeeAssignment::ACTIVE) {
                $errors[] = $this->error('employee_number', 'ASSIGNMENT_CONFLICT', 'Only an approved employee with an active placement can be moved.');
            }
            if (($earlier = $this->remember('employee', $row['employee_number'], $number)) !== null) {
                $errors[] = $this->error('employee_number', 'ASSIGNMENT_CONFLICT', "This employee is already moved on row {$earlier}.");
            }
        }

        $effective = $row['effective_from'] === null ? null : $this->pastDate($row['effective_from']);
        if ($row['effective_from'] !== null && $effective === null) {
            $errors[] = $this->error('effective_from', 'INVALID_DATE', 'Use a date that is not in the future: future placements are not supported.');
        } elseif ($effective !== null && $current?->effective_from !== null && $effective->lte($current->effective_from)) {
            $errors[] = $this->error('effective_from', 'INVALID_DATE', 'The move must start after the current placement began.');
        }

        $position = $row['position_code'] === null ? null : Position::query()->with('organization:id,status')->where('job_position_code', $row['position_code'])->first();
        if ($row['position_code'] !== null && $position === null) {
            $errors[] = $this->error('position_code', 'UNKNOWN_POSITION', 'No position has this code.');
        } elseif ($position !== null) {
            if (! $this->inScope($uploader, (string) $position->organization_id)) {
                $errors[] = $this->error('position_code', 'OUT_OF_SCOPE_ORGANIZATION', 'This position is outside your organizations.');
            } elseif (! $position->is_active || $position->organization?->status !== Organization::ACTIVE) {
                $errors[] = $this->error('position_code', 'POSITION_INACTIVE', 'The position must be active and in an organization in service.');
            } elseif ($current !== null && (string) $current->position_id === (string) $position->id) {
                $errors[] = $this->error('position_code', 'ASSIGNMENT_CONFLICT', 'The employee already holds this position.');
            }
        }

        // Seats last: only a row that will be imported takes one of the file's free seats.
        if ($errors === [] && $position !== null) {
            $free = $this->seats->capacity((string) $position->id) - $this->seats->holders((string) $position->id) - $this->count('seat', (string) $position->id);
            if ($free < 1) {
                $errors[] = $this->error('position_code', 'POSITION_ALREADY_OCCUPIED', 'This position has no free seat left (counting earlier rows).');
            } else {
                $this->increment('seat', (string) $position->id);
            }
        }

        return $errors;
    }

    public function process(array $row, ImportBatch $batch, User $actor): array
    {
        $employee = Employee::query()->where('employee_number', $row['employee_number'])->firstOrFail();
        $assignment = app(TransferEmployee::class)->execute($employee, (string) Position::query()->where('job_position_code', $row['position_code'])->value('id'),
            (string) $row['effective_from'], $actor, $row['reason'] ?? 'Imported placement change');

        return ['EmployeeAssignment', (string) $assignment->id];
    }
}
