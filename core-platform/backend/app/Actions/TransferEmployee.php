<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an employee to another position: the current placement closes the day
 * before the effective date and stays as history, a new current placement opens
 * and the employee's pointer moves. The employee's UUID and number never change.
 * Only an active placement of an employed person can be transferred.
 */
final readonly class TransferEmployee
{
    public function __construct(private SeatCapacity $seats, private OrganizationScope $scope, private AuditWriter $audit) {}

    public function execute(Employee $employee, string $positionId, string $effectiveDate, User $actor, ?string $reason = null): EmployeeAssignment
    {
        return DB::transaction(function () use ($employee, $positionId, $effectiveDate, $actor, $reason): EmployeeAssignment {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $current = EmployeeAssignment::query()->lockForUpdate()->find($employee->current_assignment_id);
            $target = Position::query()->lockForUpdate()->find($positionId);
            $effective = Carbon::parse($effectiveDate)->startOfDay();

            $problem = match (true) {
                $effective->isFuture() => ['effective_date' => 'Future transfers require scheduled activation and are not yet supported.'],
                $employee->isSeparated() => ['employee' => 'The employment has ended; it cannot be transferred.'],
                $current === null || (string) $current->employee_id !== (string) $employee->id || ! $current->is_current || $current->assignment_status !== EmployeeAssignment::ACTIVE => ['employee' => 'Only an active placement can be transferred.'],
                $target === null || ! $target->is_active || $target->organization?->status !== Organization::ACTIVE => ['position_id' => 'The target position must exist, be active and belong to an organization.'],
                (string) $target->id === (string) $current->position_id => ['position_id' => 'The employee already holds this position.'],
                ! $this->scope->allows($actor, (string) $current->organization_id) || ! $this->scope->allows($actor, (string) $target->organization_id) => ['position_id' => 'Both placements must be inside your organizations.'],
                $current->effective_from !== null && $effective->lte($current->effective_from) => ['effective_date' => 'The transfer must start after the current placement began.'],
                ! $this->seats->hasFreeSeat((string) $target->id, $effective) => ['position_id' => 'The target position has no free seat.'],
                default => null,
            };
            if ($problem !== null) {
                throw ValidationException::withMessages($problem);
            }

            $current->forceFill([
                'is_current' => false,
                'assignment_status' => EmployeeAssignment::CLOSED,
                'effective_to' => $effective->copy()->subDay()->toDateString(),
            ])->save();

            $next = EmployeeAssignment::query()->create([
                'employee_id' => $employee->id,
                'organization_id' => $target->organization_id,
                'organization_unit_id' => $target->organization_unit_id,
                'position_id' => $target->id,
                'assignment_status' => EmployeeAssignment::ACTIVE,
                'is_current' => true,
                'effective_from' => $effective->toDateString(),
                'reason' => $reason ?? 'transferred',
            ]);
            $employee->forceFill(['current_assignment_id' => $next->id])->save();

            $this->audit->write('assignment.transferred', $actor, $employee, (string) $target->organization_id, [
                'assignment_id' => $current->id, 'position_id' => $current->position_id,
            ], [
                'assignment_id' => $next->id, 'position_id' => $target->id, 'effective_from' => $next->effective_from?->toDateString(),
            ], $reason);

            return $next;
        });
    }
}
