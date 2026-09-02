<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationScopeType;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportBatchRow;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Employees\EmployeeCsvImportService;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** An organization with a unit and two vacant positions. */
function importOrg(string $prefix): array
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'IMP-TYPE'],
        ['name_en' => 'Import Type'],
    );

    $org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $prefix.'-ORG',
        'name_en' => $prefix.' Organization',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'code' => $prefix.'-U1',
        'name_en' => $prefix.' Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $positions = [];

    foreach ([1, 2] as $n) {
        $positions[$n] = Position::query()->create([
            'organization_id' => $org->id,
            'organization_unit_id' => $unit->id,
            'job_position_code' => $prefix.'-P'.$n,
            'title_en' => $prefix.' Officer '.$n,
            'is_active' => true,
        ]);
    }

    return compact('org', 'unit', 'positions');
}

/** Build a CSV upload from row arrays keyed by column name. */
function csvUpload(array $rows, string $name = 'employees.csv'): UploadedFile
{
    $columns = EmployeeCsvImportService::COLUMNS;
    $lines = [implode(',', $columns)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            static fn (string $column): string => (string) ($row[$column] ?? ''),
            $columns,
        ));
    }

    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, implode("\n", $lines)."\n");

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([
        'employees.import.view',
        'employees.import.upload',
        'employees.import.confirm',
        'employees.view',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions(Permission::all());
    // Holds no import permission at all — the unauthorised baseline.
    Role::findOrCreate('Employee', 'web');

    /*
     * Employee numbers come from the code-rule engine, which needs an active
     * rule for the Employee entity. Mirrors DatabaseSeeder and the other
     * employee feature tests.
     */
    CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::Employee->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Employee Number',
        'prefix' => 'EMP',
        'format' => '{PREFIX}-{SEQUENCE}',
        'separator' => '-',
        'sequence_length' => 6,
        'next_number' => 1,
        'initial_sequence_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Auto,
        'sequence_scope_tokens' => [],
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => true,
        'require_approval_for_override' => false,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Employee),
    ]);

    $this->alpha = importOrg('ALPHA');
    $this->beta = importOrg('BETA');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');

    $this->service = app(EmployeeCsvImportService::class);
});

/** A well-formed row for the given context. */
function importRow(array $ctx, int $position = 1, array $overrides = []): array
{
    return array_merge([
        'employee_number' => '',
        'first_name' => 'Abebe',
        'father_name' => 'Kebede',
        'grandfather_name' => 'Tesfaye',
        'gender' => 'male',
        'phone' => '0911000000',
        'email' => 'abebe@example.et',
        'organization_code' => $ctx['org']->code,
        'organization_unit_code' => $ctx['unit']->code,
        'position_code' => $ctx['positions'][$position]->job_position_code,
        'employment_status' => 'active',
        'assignment_start_date' => '2026-01-01',
    ], $overrides);
}

it('opens the csv upload page for an authorised user', function (): void {
    $this->actingAs($this->admin)
        ->get(route('employees.import.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/ImportCsv')
            ->where('can.upload', true)
            ->where('can.confirm', true)
        );
});

it('blocks a user without import permission', function (): void {
    $outsider = User::factory()->create();
    $outsider->assignRole('Employee');

    $this->actingAs($outsider)->get(route('employees.import.create'))->assertForbidden();
    $this->actingAs($outsider)
        ->post(route('employees.import.store'), ['file' => csvUpload([importRow($this->alpha)])])
        ->assertForbidden();
});

it('rejects a file that is not a csv', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'imp').'.pdf';
    file_put_contents($path, '%PDF-1.4');

    $this->actingAs($this->admin)
        ->post(route('employees.import.store'), [
            'file' => new UploadedFile($path, 'employees.pdf', 'application/pdf', null, true),
        ])
        ->assertSessionHasErrors('file');

    expect(Employee::query()->count())->toBe(0);
});

it('serves a csv template with the expected columns', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('employees.import.template'))
        ->assertOk();

    $body = $response->getContent();

    foreach (EmployeeCsvImportService::COLUMNS as $column) {
        expect($body)->toContain($column);
    }
});

it('catches rows missing required fields', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1, ['first_name' => '', 'gender' => ''])]),
        $this->admin,
    );

    expect($batch->failed_rows)->toBe(1)
        ->and($batch->valid_rows)->toBe(0)
        ->and($batch->isImportable())->toBeFalse();

    $errors = EmployeeImportBatchRow::query()->firstOrFail()->errors;

    expect($errors)->not->toBeEmpty();
});

it('validates a clean file and writes nothing to employees', function (): void {
    $batch = $this->service->validate(csvUpload([importRow($this->alpha)]), $this->admin);

    expect($batch->status)->toBe(EmployeeImportBatch::STATUS_VALIDATED)
        ->and($batch->valid_rows)->toBe(1)
        ->and($batch->failed_rows)->toBe(0)
        // Validation is a dry run: no employee exists until confirm.
        ->and(Employee::query()->count())->toBe(0);
});

it('imports employees and their assignments', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1), importRow($this->alpha, 2, ['first_name' => 'Bekele'])]),
        $this->admin,
    );

    $result = $this->service->import($batch, $this->admin);

    expect($result['imported'])->toBe(2)
        ->and(Employee::query()->count())->toBe(2);

    $employee = Employee::query()->where('first_name', 'Abebe')->firstOrFail();
    $assignment = EmployeeAssignment::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($assignment->organization_id)->toBe($this->alpha['org']->id)
        ->and($assignment->position_id)->toBe($this->alpha['positions'][1]->id)
        ->and($assignment->is_current)->toBeTrue()
        ->and($employee->full_name)->toBe('Abebe Kebede Tesfaye');
});

it('generates an employee number when the column is blank', function (): void {
    $batch = $this->service->validate(csvUpload([importRow($this->alpha)]), $this->admin);
    $this->service->import($batch, $this->admin);

    $employee = Employee::query()->firstOrFail();

    // The Code Rule supplies one; the row left it empty.
    expect($employee->employee_number)->not->toBeNull()
        ->and($employee->employee_number)->not->toBe('');
});

it('stores a random employee number in preview and uses the same value on confirm', function (): void {
    CodeRule::query()
        ->where('entity_type', CodeRuleEntityType::Employee->value)
        ->update(['format' => 'EMP-{RAND_6}']);

    $batch = $this->service->validate(csvUpload([importRow($this->alpha)]), $this->admin);
    $previewedNumber = $this->service->preview($batch)[0]['employee_number'];

    expect($previewedNumber)->toMatch('/^EMP-\d{6}$/');

    $this->service->import($batch, $this->admin);

    expect(Employee::query()->firstOrFail()->employee_number)->toBe($previewedNumber);
});

it('keeps an employee number supplied in the file', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1, ['employee_number' => 'CSV-0001'])]),
        $this->admin,
    );

    $this->service->import($batch, $this->admin);

    expect(Employee::query()->firstOrFail()->employee_number)->toBe('CSV-0001');
});

it('rejects an employee number that already exists', function (): void {
    Employee::query()->create([
        'employee_number' => 'CSV-DUP',
        'first_name' => 'Existing',
        'last_name' => 'Person',
        'full_name' => 'Existing Person',
        'status' => EmployeeStatus::Active->value,
    ]);

    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1, ['employee_number' => 'CSV-DUP'])]),
        $this->admin,
    );

    expect($batch->failed_rows)->toBe(1)
        ->and($batch->isImportable())->toBeFalse();
});

it('rejects a duplicate employee number within one file', function (): void {
    $batch = $this->service->validate(
        csvUpload([
            importRow($this->alpha, 1, ['employee_number' => 'CSV-SAME']),
            importRow($this->alpha, 2, ['employee_number' => 'CSV-SAME']),
        ]),
        $this->admin,
    );

    // The first is fine; the second collides with it.
    expect($batch->valid_rows)->toBe(1)
        ->and($batch->failed_rows)->toBe(1);
});

it('rejects a position that is already occupied', function (): void {
    $occupant = Employee::query()->create([
        'employee_number' => 'OCC-1',
        'first_name' => 'Sitting',
        'last_name' => 'Tenant',
        'full_name' => 'Sitting Tenant',
        'status' => EmployeeStatus::Active->value,
    ]);

    EmployeeAssignment::query()->create([
        'employee_id' => $occupant->id,
        'organization_id' => $this->alpha['org']->id,
        'organization_unit_id' => $this->alpha['unit']->id,
        'position_id' => $this->alpha['positions'][1]->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $batch = $this->service->validate(csvUpload([importRow($this->alpha, 1)]), $this->admin);

    expect($batch->failed_rows)->toBe(1);

    $errors = implode(' ', EmployeeImportBatchRow::query()->firstOrFail()->errors);

    expect($errors)->toContain('occupied');
});

it('rejects the same position claimed twice in one file', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1), importRow($this->alpha, 1, ['first_name' => 'Second'])]),
        $this->admin,
    );

    expect($batch->valid_rows)->toBe(1)
        ->and($batch->failed_rows)->toBe(1);
});

it('rejects an unknown organization code', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1, ['organization_code' => 'NO-SUCH-ORG'])]),
        $this->admin,
    );

    expect($batch->failed_rows)->toBe(1);
});

it('rejects a position that belongs to another organization', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1, [
            'position_code' => $this->beta['positions'][1]->job_position_code,
        ])]),
        $this->admin,
    );

    expect($batch->failed_rows)->toBe(1);
});

it('refuses rows outside an organizational admin scope', function (): void {
    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $batch = $this->service->validate(
        csvUpload([
            importRow($this->alpha, 1),
            // BETA is outside this admin's scope.
            importRow($this->beta, 1, ['first_name' => 'Outside']),
        ]),
        $scoped,
    );

    expect($batch->valid_rows)->toBe(1)
        ->and($batch->failed_rows)->toBe(1);

    $outsideRow = EmployeeImportBatchRow::query()->where('row_number', 3)->firstOrFail();

    expect(implode(' ', $outsideRow->errors))->toContain('outside your organization scope');
});

it('lets an unrestricted admin import across organizations', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1), importRow($this->beta, 1, ['first_name' => 'Bekele'])]),
        $this->admin,
    );

    expect($batch->failed_rows)->toBe(0);

    $this->service->import($batch, $this->admin);

    expect(Employee::query()->count())->toBe(2);
});

it('does not partially import when a row turns invalid before confirm', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1), importRow($this->alpha, 2, ['first_name' => 'Bekele'])]),
        $this->admin,
    );

    expect($batch->isImportable())->toBeTrue();

    /*
     * Simulate a race: someone fills the second position between preview and
     * confirm. The whole import must roll back, not load the first row only.
     */
    $occupant = Employee::query()->create([
        'employee_number' => 'RACE-1',
        'first_name' => 'Race',
        'last_name' => 'Winner',
        'full_name' => 'Race Winner',
        'status' => EmployeeStatus::Active->value,
    ]);

    EmployeeAssignment::query()->create([
        'employee_id' => $occupant->id,
        'organization_id' => $this->alpha['org']->id,
        'position_id' => $this->alpha['positions'][2]->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    expect(fn () => $this->service->import($batch, $this->admin))
        ->toThrow(RuntimeException::class);

    // Only the pre-existing occupant remains — neither CSV row was written.
    expect(Employee::query()->count())->toBe(1)
        ->and(Employee::query()->where('first_name', 'Abebe')->exists())->toBeFalse();
});

it('refuses to import a batch that still has invalid rows', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1, ['first_name' => ''])]),
        $this->admin,
    );

    expect(fn () => $this->service->import($batch, $this->admin))
        ->toThrow(RuntimeException::class);

    expect(Employee::query()->count())->toBe(0);
});

it('records the batch and every row', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1), importRow($this->alpha, 1, ['first_name' => 'Clash'])], 'staff.csv'),
        $this->admin,
    );

    expect($batch->file_name)->toBe('staff.csv')
        ->and($batch->total_rows)->toBe(2)
        ->and((string) $batch->uploaded_by)->toBe((string) $this->admin->getKey())
        ->and(EmployeeImportBatchRow::query()->where('batch_id', $batch->id)->count())->toBe(2);

    // The raw row is kept verbatim for later dispute resolution.
    $first = EmployeeImportBatchRow::query()->where('row_number', 2)->firstOrFail();

    expect($first->row_data['first_name'])->toBe('Abebe');
});

it('links imported rows to the employees they created', function (): void {
    $batch = $this->service->validate(csvUpload([importRow($this->alpha)]), $this->admin);
    $this->service->import($batch, $this->admin);

    $row = EmployeeImportBatchRow::query()->firstOrFail();

    expect($row->status)->toBe(EmployeeImportBatchRow::STATUS_IMPORTED)
        ->and($row->employee_id)->toBe(Employee::query()->firstOrFail()->id);

    expect($batch->fresh()->status)->toBe(EmployeeImportBatch::STATUS_IMPORTED);
});

it('blocks confirming another user batch', function (): void {
    $batch = $this->service->validate(csvUpload([importRow($this->alpha)]), $this->admin);

    $other = User::factory()->create();
    $other->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $other->id,
        'organization_id' => $this->beta['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    // A batch id in the URL must not be enough to commit someone else's upload.
    $this->actingAs($other)
        ->post(route('employees.import.confirm', $batch->id))
        ->assertForbidden();

    expect(Employee::query()->count())->toBe(0);
});

it('imports through the http flow end to end', function (): void {
    $this->actingAs($this->admin)
        ->post(route('employees.import.store'), ['file' => csvUpload([importRow($this->alpha)])])
        ->assertRedirect(route('employees.import.create'));

    $batch = EmployeeImportBatch::query()->firstOrFail();

    expect($batch->isImportable())->toBeTrue();

    $this->actingAs($this->admin)
        ->post(route('employees.import.confirm', $batch->id))
        ->assertRedirect(route('employees.index'));

    expect(Employee::query()->count())->toBe(1);
});
