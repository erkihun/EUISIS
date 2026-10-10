<?php

declare(strict_types=1);

use App\Actions\Employees\RegisterEmployeeAction;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
 * Risk register CON-10: validation refused a known duplicate national ID, but
 * two simultaneous registrations could both pass it. The database now backs
 * the rule (data:audit-duplicates found no shared IDs; the migration refuses
 * to run where any exist).
 */

it('backs national-id uniqueness with a unique index', function (): void {
    expect(Schema::hasIndex('employees', 'employees_national_id_hash_unique'))->toBeTrue();
});

it('turns a duplicate national id that slips past validation into a validation error', function (): void {
    $type = OrganizationType::query()->create(['code' => 'NID-T', 'name_en' => 'NID type']);
    $org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'NID-ORG', 'name_en' => 'NID org', 'status' => 'active']);
    $register = fn (string $number) => app(RegisterEmployeeAction::class)->execute(
        ['employee_number' => $number, 'first_name' => 'Same', 'last_name' => 'Person', 'full_name' => 'Same Person',
            'national_id' => '1234567890123456', 'status' => EmployeeStatus::Active],
        ['organization_id' => $org->id, 'effective_from' => now()->toDateString()],
        User::factory()->create(),
    );

    $register('NID-1');

    expect(fn () => $register('NID-2'))->toThrow(function (ValidationException $e): void {
        expect($e->errors())->toHaveKey('national_id');
    });
    expect(Employee::query()->where('national_id_hash', hash('sha256', '1234567890123456'))->count())->toBe(1);
});
