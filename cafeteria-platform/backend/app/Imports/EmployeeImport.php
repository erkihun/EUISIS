<?php

declare(strict_types=1);

namespace App\Imports;

use App\Actions\RegisterEmployee;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Services\DuplicateEmployees;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;

/**
 * Employees (ADR 0009) through RegisterEmployee: the same validation, number
 * generation, duplicate detection and scope as one registration, so an
 * imported employee is a draft with a pending first placement that goes
 * through verification and approval like any other. A possible duplicate is
 * imported only with a reason, and then waits in the review queue.
 */
final class EmployeeImport extends ImportDefinition
{
    public function __construct(OrganizationScope $scope, private readonly DuplicateEmployees $duplicates, private readonly SeatCapacity $seats)
    {
        parent::__construct($scope);
    }

    public function columns(): array
    {
        return ['employee_number', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'phone', 'email', 'national_id',
            'position_code', 'effective_from', 'duplicate_reason'];
    }

    public function permission(): string
    {
        return 'employees.create';
    }

    public function validate(array $row, int $number, User $uploader): array
    {
        $errors = $this->basic($row, [
            'employee_number' => ['nullable', 'max:255'], 'first_name' => ['required', 'max:255'], 'middle_name' => ['nullable', 'max:255'],
            'last_name' => ['required', 'max:255'], 'gender' => ['nullable', 'max:32'], 'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'national_id' => ['nullable', 'max:64'],
            'position_code' => ['required'], 'effective_from' => ['required', 'date'], 'duplicate_reason' => ['nullable', 'max:1000'],
        ]);
        if ($row['employee_number'] !== null) {
            if (Employee::query()->where('employee_number', $row['employee_number'])->exists()) {
                $errors[] = $this->error('employee_number', 'DUPLICATE_EMPLOYEE_NUMBER', 'This employee number is already used.');
            } elseif (($earlier = $this->remember('number', $row['employee_number'], $number)) !== null) {
                $errors[] = $this->error('employee_number', 'DUPLICATE_IN_FILE', "This employee number is already on row {$earlier}.");
            }
        }
        $hash = DuplicateEmployees::nationalIdHash($row['national_id']);
        if ($hash !== null) {
            if (Employee::query()->where('national_id_hash', $hash)->exists()) {
                $errors[] = $this->error('national_id', 'DUPLICATE_NATIONAL_ID', 'A person with this national ID is already registered.');
            } elseif (($earlier = $this->remember('national_id', $hash, $number)) !== null) {
                $errors[] = $this->error('national_id', 'DUPLICATE_NATIONAL_ID', "The same national ID is on row {$earlier}.");
            }
        }
        if ($row['effective_from'] !== null && $this->pastDate($row['effective_from']) === null) {
            $errors[] = $this->error('effective_from', 'INVALID_DATE', 'Use a date that is not in the future: future placements are not supported.');
        }

        $position = $row['position_code'] === null ? null : Position::query()->with('organization:id,status')->where('job_position_code', $row['position_code'])->first();
        if ($row['position_code'] !== null && $position === null) {
            $errors[] = $this->error('position_code', 'UNKNOWN_POSITION', 'No position has this code.');
        } elseif ($position !== null) {
            if (! $this->inScope($uploader, (string) $position->organization_id)) {
                $errors[] = $this->error('position_code', 'OUT_OF_SCOPE_ORGANIZATION', 'This position is outside your organizations.');
            } elseif (! $position->is_active || $position->organization?->status !== Organization::ACTIVE) {
                $errors[] = $this->error('position_code', 'POSITION_INACTIVE', 'The position must be active and in an organization in service.');
            }
        }

        $fullName = trim(implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['last_name']])));
        if ($fullName !== '') {
            if (($earlier = $this->remember('person', $fullName.'|'.$row['date_of_birth'], $number)) !== null) {
                $errors[] = $this->error('first_name', 'DUPLICATE_IN_FILE', "The same person is on row {$earlier}.");
            }
            $matches = $this->duplicates->matches(['full_name' => $fullName, 'date_of_birth' => $row['date_of_birth'], 'phone' => $row['phone'], 'email' => $row['email']], $uploader);
            if ($matches !== [] && mb_strlen((string) $row['duplicate_reason']) < 5) {
                $strong = collect($matches)->contains('level', DuplicateEmployees::STRONG);
                $errors[] = $this->error('duplicate_reason', $strong ? 'DUPLICATE_EMPLOYEE' : 'POSSIBLE_DUPLICATE',
                    'This person may already be registered ('.collect($matches)->pluck('employee_number')->implode(', ').'). Say why this is a different person in duplicate_reason.');
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
        $employee = app(RegisterEmployee::class)->execute(array_filter([
            'employee_number' => $row['employee_number'],
            'first_name' => $row['first_name'],
            'middle_name' => $row['middle_name'],
            'last_name' => $row['last_name'],
            'gender' => $row['gender'],
            'date_of_birth' => $row['date_of_birth'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'national_id' => $row['national_id'],
            'position_id' => (string) Position::query()->where('job_position_code', $row['position_code'])->value('id'),
            'effective_from' => $row['effective_from'],
            'confirm_not_duplicate' => $row['duplicate_reason'] !== null,
            'duplicate_reason' => $row['duplicate_reason'],
            'duplicate_source' => 'import',
        ], fn ($value): bool => $value !== null), $actor);

        return ['Employee', (string) $employee->id];
    }
}
