<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CodeGenerator;
use App\Services\DuplicateEmployees;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers an employee with their first placement, in one transaction: the
 * position is locked, must be active, in an organization in service, inside
 * the actor's scope and have a free seat; the employee number is the one typed
 * or the next one from Core settings › Numbering; the person is looked up
 * among every employee first (DuplicateEmployees) — the same national ID is
 * refused, a name/phone/e-mail match stops the registration until the officer
 * records that it is a different person; the employee points at the new
 * current assignment; everything is audited. The employee starts as a draft
 * with a pending placement: it becomes active only through submission,
 * verification and approval by others (EmployeeVerification, ADR 0008).
 */
final readonly class RegisterEmployee
{
    public function __construct(
        private SeatCapacity $seats,
        private OrganizationScope $scope,
        private AuditWriter $audit,
        private CodeGenerator $codes,
        private DuplicateEmployees $duplicates,
        private ReviewEmployeeDuplicates $reviews,
    ) {}

    /**
     * @param  array{employee_number?: ?string, first_name: string, middle_name?: ?string, last_name: string, gender?: ?string,
     *     date_of_birth?: ?string, phone?: ?string, email?: ?string, national_id?: ?string, position_id: string, effective_from: string,
     *     confirm_not_duplicate?: bool, duplicate_reason?: ?string}  $data
     */
    public function execute(array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor): Employee {
            if (Carbon::parse($data['effective_from'])->startOfDay()->isFuture()) {
                throw ValidationException::withMessages(['effective_from' => 'Future placements require scheduled activation and are not yet supported.']);
            }
            $position = Position::query()->lockForUpdate()->find($data['position_id']);
            if ($position === null || ! $position->is_active || $position->organization?->status !== Organization::ACTIVE) {
                throw ValidationException::withMessages(['position_id' => 'The position must exist, be active and belong to an organization in service.']);
            }
            if (! $this->scope->allows($actor, (string) $position->organization_id)) {
                throw ValidationException::withMessages(['position_id' => 'The position is outside your organizations.']);
            }
            if (! $this->seats->hasFreeSeat((string) $position->id)) {
                throw ValidationException::withMessages(['position_id' => 'This position is already occupied: it has no free seat.']);
            }

            $fullName = trim(implode(' ', array_filter([$data['first_name'], $data['middle_name'] ?? null, $data['last_name']])));
            $nationalIdHash = DuplicateEmployees::nationalIdHash($data['national_id'] ?? null);
            if ($nationalIdHash !== null) {
                $holder = Employee::query()->where('national_id_hash', $nationalIdHash)->first(['id', 'employee_number', 'current_assignment_id']);
                if ($holder !== null) {
                    $visible = $this->scope->allows($actor, $holder->currentAssignment?->organization_id);
                    throw ValidationException::withMessages(['national_id' => 'A person with this national ID is already registered'.($visible ? " ({$holder->employee_number})." : '.')]);
                }
            }

            $matches = $this->duplicates->matches(['full_name' => $fullName] + array_intersect_key($data, array_flip(['date_of_birth', 'phone', 'email'])), $actor);
            if ($matches !== [] && ! ($data['confirm_not_duplicate'] ?? false)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This person may already be registered. Check the matches, then confirm that this is a different person.',
                    'code' => 'POSSIBLE_DUPLICATE',
                    'errors' => ['duplicates' => ['This person may already be registered.']],
                    'matches' => array_map(fn (array $match): array => $match['in_scope'] ? $match
                        : ['employee_number' => $match['employee_number'], 'full_name' => $match['full_name'], 'level' => $match['level'], 'reasons' => $match['reasons'], 'in_scope' => false], $matches),
                ], 409));
            }
            if ($matches !== [] && mb_strlen(trim((string) ($data['duplicate_reason'] ?? ''))) < 5) {
                throw ValidationException::withMessages(['duplicate_reason' => 'Say why this is a different person (at least 5 characters).']);
            }

            $number = $this->codes->code('employee', $data['employee_number'] ?? null);
            if (Employee::query()->where('employee_number', $number)->exists()) {
                throw ValidationException::withMessages(['employee_number' => 'This employee number is already used.']);
            }

            $employee = Employee::query()->create([
                'employee_number' => $number,
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'full_name' => $fullName,
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'national_id_hash' => $nationalIdHash,
                // A draft until it is submitted, verified and approved (EmployeeVerification, ADR 0008).
                'status' => Employee::DRAFT,
                'verification_status' => Employee::DRAFT,
                'registered_by' => $actor->getKey(),
            ]);

            $assignment = EmployeeAssignment::query()->create([
                'employee_id' => $employee->id,
                'organization_id' => $position->organization_id,
                'organization_unit_id' => $position->organization_unit_id,
                'position_id' => $position->id,
                // Holds no seat until approval activates it; the seat is checked again then.
                'assignment_status' => EmployeeAssignment::PENDING,
                'is_current' => true,
                'effective_from' => $data['effective_from'],
                'reason' => 'registered',
                'assignment_type' => 'initial',
                'reason_code' => 'registration',
                'created_by' => $actor->getKey(),
                'source_system' => in_array($data['source_system'] ?? null, EmployeeAssignment::SOURCES, true) ? $data['source_system'] : 'core',
                'external_reference' => $data['external_reference'] ?? null,
            ]);
            $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

            $this->audit->write('employee.registered', $actor, $employee, (string) $position->organization_id, null, [
                'employee_number' => $employee->employee_number, 'position_id' => $position->id, 'assignment_id' => $assignment->id,
            ]);
            // The registrar's "a different person" is a statement, not the decision: a reviewer decides (ADR 0009).
            $this->reviews->record($employee, $matches, ($data['duplicate_source'] ?? null) === 'import' ? 'import' : 'registration', $data['duplicate_reason'] ?? null, $actor);
            if ($matches !== []) {
                $this->audit->write('employee.duplicate_override', $actor, $employee, (string) $position->organization_id, null, [
                    'matched_employee_numbers' => array_column($matches, 'employee_number'),
                    'levels' => array_values(array_unique(array_column($matches, 'level'))),
                ], $data['duplicate_reason']);
            }

            return $employee->fresh(['currentAssignment']);
        });
    }
}
