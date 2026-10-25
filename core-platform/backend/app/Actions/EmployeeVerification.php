<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\DuplicateEmployees;
use App\Services\EmployeeEvidence;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The verified-employee stage of the Golden Path (ADR 0008), the one place its
 * transitions happen:
 *
 *   draft → submit → submitted → verify → verified → approve → active
 *   submitted / verified → return (with a reason) → draft
 *
 * Verification ticks a checklist (identity, official name, date of birth and
 * national ID when on record, organization, position, placement; photo and
 * documents once Core settings › Workforce require them) and needs the
 * duplicate check clear. Approval is a separate privileged step by someone
 * who did not register, submit or verify the record; it activates the
 * employee and their pending first placement together, re-checking the
 * position, its organization and the seat. Every step locks the employee row
 * (two people cannot move one record twice), runs in one transaction, checks
 * the actor's permission and scope, and is audited.
 */
final readonly class EmployeeVerification
{
    /** Checks a verifier always confirms. */
    public const BASE_CHECKS = ['identity_confirmed', 'official_name_checked', 'organization_confirmed', 'position_confirmed', 'assignment_confirmed'];

    public function __construct(
        private AuditWriter $audit,
        private OrganizationScope $scope,
        private SeatCapacity $seats,
        private CoreSettings $settings,
        private DuplicateEmployees $duplicates,
        private EmployeeEvidence $evidence,
        private ReviewEmployeeDuplicates $reviews,
    ) {}

    /**
     * The checklist for this employee: key => whether it is required.
     *
     * @return array<string, bool>
     */
    public function checklist(Employee $employee): array
    {
        return array_fill_keys(self::BASE_CHECKS, true) + [
            'date_of_birth_checked' => $employee->date_of_birth !== null,
            'national_id_checked' => $employee->national_id_hash !== null,
        ];
    }

    public function submit(Employee $employee, User $actor, ?string $notes = null): Employee
    {
        return $this->step($employee, $actor, 'employees.submit', function (Employee $employee) use ($actor, $notes): array {
            $this->expect($employee, Employee::DRAFT, 'This employee must be a draft to be submitted.');
            $employee->forceFill([
                'status' => Employee::SUBMITTED, 'verification_status' => 'submitted',
                'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'verification_notes' => $notes,
                'verified_by' => null, 'verified_at' => null, 'verification_checklist' => null,
            ])->save();

            return ['employee.submitted', $notes];
        });
    }

    /** @param array<string, bool> $checks */
    public function verify(Employee $employee, User $actor, array $checks, ?string $notes = null): Employee
    {
        return $this->step($employee, $actor, 'employees.verify', function (Employee $employee) use ($actor, $checks, $notes): array {
            if ($employee->status === Employee::VERIFIED) {
                throw ValidationException::withMessages(['employee' => 'This employee has already been verified.']);
            }
            $this->expect($employee, Employee::SUBMITTED, 'This employee must be submitted before verification.');
            if ((int) $employee->submitted_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['employee' => 'Someone other than the person who submitted the employee must verify it.']);
            }
            $missing = array_keys(array_filter($this->checklist($employee), fn (bool $required, string $key): bool => $required && ! ($checks[$key] ?? false), ARRAY_FILTER_USE_BOTH));
            if ($missing !== []) {
                throw ValidationException::withMessages(['checks' => 'Confirm every required check: '.implode(', ', $missing).'.']);
            }
            $this->assertEvidence($employee);
            $this->assertNoOpenDuplicates($employee, $actor);
            $employee->forceFill([
                'status' => Employee::VERIFIED, 'verification_status' => 'verified',
                'verified_by' => $actor->getKey(), 'verified_at' => now(), 'verification_notes' => $notes,
                'verification_checklist' => array_intersect_key($checks, $this->checklist($employee)) + ['duplicate_check_passed' => true],
            ])->save();

            return ['employee.verified', $notes];
        });
    }

    public function returnForCorrection(Employee $employee, User $actor, string $reason): Employee
    {
        return $this->step($employee, $actor, 'employees.return', function (Employee $employee) use ($reason): array {
            if (! in_array($employee->status, [Employee::SUBMITTED, Employee::VERIFIED], true)) {
                throw ValidationException::withMessages(['employee' => 'Only a submitted or verified employee can be returned for correction.']);
            }
            $employee->forceFill([
                'status' => Employee::DRAFT, 'verification_status' => 'returned', 'verification_notes' => $reason,
                'submitted_by' => null, 'submitted_at' => null, 'verified_by' => null, 'verified_at' => null, 'verification_checklist' => null,
            ])->save();

            return ['employee.returned', $reason];
        });
    }

    public function approve(Employee $employee, User $actor, ?string $notes = null): Employee
    {
        return $this->step($employee, $actor, 'employees.approve', function (Employee $employee) use ($actor, $notes): array {
            if ($employee->isApproved()) {
                throw ValidationException::withMessages(['employee' => 'This employee has already been approved.']);
            }
            $this->expect($employee, Employee::VERIFIED, 'This employee must be verified before approval.');
            $actorId = (int) $actor->getKey();
            if (in_array($actorId, array_map('intval', array_filter([$employee->registered_by, $employee->submitted_by, $employee->verified_by])), true)) {
                throw ValidationException::withMessages(['employee' => 'You cannot approve an employee you registered, submitted or verified.']);
            }

            $placement = EmployeeAssignment::query()->lockForUpdate()->find($employee->current_assignment_id);
            if ($placement === null || (string) $placement->employee_id !== (string) $employee->id || $placement->assignment_status !== EmployeeAssignment::PENDING) {
                throw ValidationException::withMessages(['employee' => 'The employee has no pending first placement to activate.']);
            }
            $position = Position::query()->lockForUpdate()->find($placement->position_id);
            if ($position === null || ! $position->is_active || $position->organization?->status !== Organization::ACTIVE) {
                throw ValidationException::withMessages(['employee' => 'The position must still be active and in an organization in service.']);
            }
            $this->assertEvidence($employee);
            $this->assertNoOpenDuplicates($employee, $actor);
            if (! $this->seats->hasFreeSeat((string) $position->id)) {
                throw ValidationException::withMessages(['employee' => 'This position is already occupied: it has no free seat.']);
            }

            $placement->forceFill(['assignment_status' => EmployeeAssignment::ACTIVE])->save();
            $employee->forceFill([
                'status' => Employee::ACTIVE, 'verification_status' => 'approved',
                'approved_by' => $actorId, 'approved_at' => now(),
            ])->save();

            return ['employee.approved', $notes];
        });
    }

    /**
     * One locked, scoped, permission-checked, audited transition.
     *
     * @param  \Closure(Employee): array{0: string, 1: ?string}  $change  returns [audit action, reason]
     */
    private function step(Employee $employee, User $actor, string $permission, \Closure $change): Employee
    {
        return DB::transaction(function () use ($employee, $actor, $permission, $change): Employee {
            $employee = Employee::query()->with('currentAssignment')->lockForUpdate()->findOrFail($employee->id);
            abort_unless($actor->is_active && $actor->can($permission), 403, 'You may not take this step for an employee.');
            $organizationId = $employee->currentAssignment?->organization_id;
            abort_unless($this->scope->allows($actor, $organizationId === null ? null : (string) $organizationId), 403, 'You do not have access to this organization.');
            $before = $employee->status;
            [$action, $reason] = $change($employee);
            $this->audit->write($action, $actor, $employee, $organizationId === null ? null : (string) $organizationId,
                ['status' => $before], ['status' => $employee->status, 'employee_number' => $employee->employee_number, 'assignment_id' => $employee->current_assignment_id], $reason);

            return $employee->fresh(['currentAssignment']);
        });
    }

    /**
     * The identity evidence approval needs (EmployeeEvidence, Core settings): each
     * missing item is its own error, keyed by its code (evidence.PHOTO_REQUIRED, …).
     */
    private function assertEvidence(Employee $employee): void
    {
        $failures = $this->evidence->failures($employee, $this->evidence->forApproval());
        if ($failures !== []) {
            throw ValidationException::withMessages(collect($failures)->mapWithKeys(fn (string $message, string $code): array => ['evidence.'.$code => $message])->all());
        }
    }

    private function expect(Employee $employee, string $status, string $message): void
    {
        if ($employee->status !== $status) {
            throw ValidationException::withMessages(['employee' => $message]);
        }
    }

    /**
     * Every possible duplicate is reviewed and none is confirmed (ADR 0009): a
     * match with no review yet, an open review, or a confirmed duplicate stops it.
     */
    private function assertNoOpenDuplicates(Employee $employee, User $actor): void
    {
        $matches = $this->duplicates->matches([
            'full_name' => (string) $employee->full_name,
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
            'phone' => $employee->phone,
            'email' => $employee->email,
        ], $actor, (string) $employee->id);
        $reviewed = DB::table('employee_duplicate_reviews')
            ->where(fn ($q) => $q->where('employee_id', $employee->id)->orWhere('matched_employee_id', $employee->id))
            ->get(['employee_id', 'matched_employee_id'])
            ->flatMap(fn ($row): array => [(string) $row->employee_id, (string) $row->matched_employee_id])->all();
        $unreviewed = array_values(array_map(fn (array $match): string => $match['employee_number'],
            array_filter($matches, fn (array $match): bool => ! in_array($match['employee_id'], $reviewed, true))));
        $blocking = $this->reviews->blocking($employee);
        if ($blocking['confirmed'] !== []) {
            throw ValidationException::withMessages(['employee' => 'This record is a confirmed duplicate of '.implode(', ', $blocking['confirmed']).'; it waits for a controlled merge.']);
        }
        $open = array_values(array_unique([...$unreviewed, ...$blocking['open']]));
        if ($open !== []) {
            throw ValidationException::withMessages(['employee' => 'Possible duplicates must be reviewed first: '.implode(', ', $open).'.']);
        }
    }
}
