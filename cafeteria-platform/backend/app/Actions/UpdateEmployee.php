<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\DuplicateEmployees;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An employee master-data change (EUISIS UpdateEmployeeAction and
 * EmploymentStatusTransition, ADR 0002):
 *  - details: names and gender; the employee number never changes (ADR 0005);
 *  - employment status: ending employment (retired, terminated, deceased,
 *    transferred out) closes the placement the same day and releases its seat,
 *    and suspends the employee's live ID cards so none stays usable; resuming
 *    reopens the last position when it still has a free seat, otherwise HR
 *    places the person with a transfer. Suspension keeps the placement.
 */
final readonly class UpdateEmployee
{
    public const STATUSES = ['active', 'suspended', 'retired', 'terminated', 'deceased', 'transferred'];

    /** The fields that identify a person: a change looks for duplicates again (ADR 0009). */
    private const IDENTITY = ['full_name', 'date_of_birth', 'phone', 'email', 'national_id_hash'];

    public function __construct(private AuditWriter $audit, private OrganizationScope $scope, private SeatCapacity $seats, private ReviewEmployeeDuplicates $duplicates) {}

    /**
     * Personal details. A national ID is kept only as its keyed hash, and one
     * person has one record: another employee's national ID is refused.
     *
     * @param  array<string, mixed>  $data
     */
    public function details(Employee $employee, array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor): Employee {
            $employee = $this->lock($employee, $actor);
            // What was submitted or verified is what gets approved: correct it after returning it to draft.
            if (in_array($employee->status, [Employee::SUBMITTED, Employee::VERIFIED], true)) {
                throw ValidationException::withMessages(['employee' => 'Return the employee to draft before correcting their details.']);
            }
            $nationalId = trim((string) ($data['national_id'] ?? ''));
            unset($data['national_id']);
            if ($nationalId !== '') {
                $hash = DuplicateEmployees::nationalIdHash($nationalId);
                if (Employee::query()->where('national_id_hash', $hash)->whereKeyNot($employee->getKey())->exists()) {
                    throw ValidationException::withMessages(['national_id' => 'A person with this national ID is already registered.']);
                }
                $data['national_id_hash'] = $hash;
            }
            $before = $employee->only(self::IDENTITY);
            $old = $employee->only(array_keys($data));
            $employee->fill($data);
            $employee->full_name = trim(implode(' ', array_filter([$employee->first_name, $employee->middle_name, $employee->last_name])));
            $employee->save();
            // The hash itself is never written to the audit, only that it changed.
            $this->audit->write('employee.updated', $actor, $employee, $this->organizationId($employee),
                array_diff_key($old, ['national_id_hash' => true]) + (isset($data['national_id_hash']) ? ['national_id' => 'previous'] : []),
                array_diff_key($employee->only(array_keys($data)), ['national_id_hash' => true]) + (isset($data['national_id_hash']) ? ['national_id' => 'changed'] : []));
            if ($employee->only(self::IDENTITY) != $before) {
                $this->duplicates->detectAfterChange($employee, $actor);
            }

            return $employee;
        });
    }

    public function status(Employee $employee, string $to, string $reason, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $to, $reason, $actor): Employee {
            $employee = $this->lock($employee, $actor);
            $from = $employee->status;
            if (! $employee->isApproved()) {
                throw ValidationException::withMessages(['status' => 'This employee has not been approved yet: submit, verify and approve it first.']);
            }
            if ($from === $to) {
                throw ValidationException::withMessages(['status' => 'The employee already has this status.']);
            }
            $separating = in_array($to, Employee::SEPARATED, true) && ! in_array($from, Employee::SEPARATED, true);
            $resuming = in_array($from, Employee::SEPARATED, true) && in_array($to, ['active', 'suspended'], true);
            $today = now()->toDateString();

            $current = EmployeeAssignment::query()->where('employee_id', $employee->id)->where('is_current', true)->lockForUpdate()->first();
            if ($separating && $current !== null && $current->assignment_status === EmployeeAssignment::ACTIVE) {
                if ($current->effective_from !== null && $today < $current->effective_from->toDateString()) {
                    throw ValidationException::withMessages(['status' => 'The placement starts after today; it cannot end before it begins.']);
                }
                $current->update(['assignment_status' => EmployeeAssignment::CLOSED, 'effective_to' => $today, 'reason' => 'employment_ended:'.$to]);
                $this->audit->write('assignment.ended', $actor, $current, (string) $current->organization_id, null, ['assignment_status' => 'closed', 'effective_to' => $today], $reason);

                IdCard::query()->where('employee_id', $employee->id)->whereIn('status', [IdCard::ACTIVE, IdCard::ISSUED])->get()
                    ->each(function (IdCard $card) use ($actor, $reason, $current): void {
                        $before = $card->status;
                        $card->update(['status' => IdCard::SUSPENDED]);
                        $this->audit->write('card.suspend', $actor, $card, (string) $current->organization_id, ['status' => $before], ['status' => IdCard::SUSPENDED], 'Employment ended: '.$reason);
                    });
            }
            if ($resuming) {
                $this->resume($employee, $current, $actor, $reason);
            }

            $employee->update(['status' => $to]);
            $this->audit->write('employee.status_changed', $actor, $employee, $this->organizationId($employee), ['status' => $from], ['status' => $to], $reason);

            return $employee->fresh(['currentAssignment']);
        });
    }

    /** EUISIS StartAssignment: back to the last position if it is active and has a free seat. */
    private function resume(Employee $employee, ?EmployeeAssignment $latest, User $actor, string $reason): void
    {
        if ($latest === null || $latest->assignment_status !== EmployeeAssignment::CLOSED || $latest->position_id === null) {
            throw ValidationException::withMessages(['status' => 'There is no earlier placement to return to; register a transfer instead.']);
        }
        $position = Position::query()->lockForUpdate()->find($latest->position_id);
        if ($position === null || ! $position->is_active || $position->organization?->status !== Organization::ACTIVE || ! $this->seats->hasFreeSeat((string) $position->id)) {
            throw ValidationException::withMessages(['status' => 'The last position is retired or full; place the employee with a transfer.']);
        }
        $today = now()->toDateString();
        if ($latest->effective_to === null || $today <= $latest->effective_to->toDateString()) {
            $latest->update(['assignment_status' => EmployeeAssignment::ACTIVE, 'effective_to' => null, 'reason' => 'employment_resumed']);
            $placement = $latest;
        } else {
            $latest->update(['is_current' => false]);
            $placement = EmployeeAssignment::query()->create([
                'employee_id' => $employee->id,
                'organization_id' => $latest->organization_id,
                'organization_unit_id' => $latest->organization_unit_id,
                'position_id' => $latest->position_id,
                'assignment_status' => EmployeeAssignment::ACTIVE,
                'effective_from' => $today,
                'is_current' => true,
                'reason' => 'employment_resumed',
            ]);
            $employee->forceFill(['current_assignment_id' => $placement->id])->save();
        }
        $this->audit->write('assignment.resumed', $actor, $placement, (string) $placement->organization_id, null, ['assignment_status' => 'active'], $reason);
    }

    private function lock(Employee $employee, User $actor): Employee
    {
        $employee = Employee::query()->with('currentAssignment')->lockForUpdate()->findOrFail($employee->id);
        if (! $this->scope->allows($actor, $employee->currentAssignment?->organization_id)) {
            throw ValidationException::withMessages(['employee' => 'This employee is outside your organizations.']);
        }

        return $employee;
    }

    private function organizationId(Employee $employee): ?string
    {
        return $employee->currentAssignment?->organization_id;
    }
}
