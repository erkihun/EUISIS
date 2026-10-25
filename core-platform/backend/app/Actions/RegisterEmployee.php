<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers an employee with their first placement, in one transaction: the
 * position is locked, must be active, inside the actor's scope and have a free
 * seat; the employee points at the new current assignment; the change is audited.
 */
final readonly class RegisterEmployee
{
    public function __construct(private SeatCapacity $seats, private OrganizationScope $scope, private AuditWriter $audit) {}

    /** @param array{employee_number: string, first_name: string, middle_name?: ?string, last_name: string, gender?: ?string, position_id: string, effective_from: string} $data */
    public function execute(array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor): Employee {
            $position = Position::query()->lockForUpdate()->find($data['position_id']);
            if ($position === null || ! $position->is_active || $position->organization_id === null) {
                throw ValidationException::withMessages(['position_id' => 'The position must exist, be active and belong to an organization.']);
            }
            if (! $this->scope->allows($actor, (string) $position->organization_id)) {
                throw ValidationException::withMessages(['position_id' => 'The position is outside your organizations.']);
            }
            if (! $this->seats->hasFreeSeat((string) $position->id)) {
                throw ValidationException::withMessages(['position_id' => 'The position has no free seat.']);
            }

            $employee = Employee::query()->create([
                'employee_number' => $data['employee_number'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'full_name' => trim(implode(' ', array_filter([$data['first_name'], $data['middle_name'] ?? null, $data['last_name']]))),
                'gender' => $data['gender'] ?? null,
                'status' => Employee::ACTIVE,
            ]);

            $assignment = EmployeeAssignment::query()->create([
                'employee_id' => $employee->id,
                'organization_id' => $position->organization_id,
                'organization_unit_id' => $position->organization_unit_id,
                'position_id' => $position->id,
                'assignment_status' => EmployeeAssignment::ACTIVE,
                'is_current' => true,
                'effective_from' => $data['effective_from'],
                'reason' => 'registered',
            ]);
            $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

            $this->audit->write('employee.registered', $actor, $employee, (string) $position->organization_id, null, [
                'employee_number' => $employee->employee_number, 'position_id' => $position->id, 'assignment_id' => $assignment->id,
            ]);

            return $employee->fresh(['currentAssignment']);
        });
    }
}
