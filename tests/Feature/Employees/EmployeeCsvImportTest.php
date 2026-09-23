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
        ->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id]))
        ->assertOk();

    $body = $response->getContent();

    foreach (EmployeeCsvImportService::templateColumns() as $column) {
        expect($body)->toContain($column);
    }

    expect(EmployeeCsvImportService::templateColumns())->not->toContain('employee_number');
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

it('rejects the upload when no employee code rule is active', function (): void {
    CodeRule::query()
        ->where('entity_type', CodeRuleEntityType::Employee->value)
        ->update(['is_active' => false]);

    $batch = $this->service->validate(csvUpload([importRow($this->alpha)]), $this->admin);

    expect($batch->failed_rows)->toBe(1)
        ->and($batch->isImportable())->toBeFalse()
        ->and(implode(' ', EmployeeImportBatchRow::query()->firstOrFail()->errors))
        ->toContain(__('code-rules.no_active_rule'));
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

it('stores a rand_8 employee number in preview and uses the same value on confirm', function (): void {
    CodeRule::query()
        ->where('entity_type', CodeRuleEntityType::Employee->value)
        ->update(['format' => 'AAC-{RAND_8}']);

    $batch = $this->service->validate(csvUpload([importRow($this->alpha)]), $this->admin);
    $previewedNumber = $this->service->preview($batch)[0]['employee_number'];

    expect($previewedNumber)->toMatch('/^AAC-\d{8}$/');

    $this->service->import($batch, $this->admin);

    // The confirmed import must not roll a second, different number.
    expect(Employee::query()->firstOrFail()->employee_number)->toBe($previewedNumber);
});

it('ignores an employee number supplied in a legacy file and uses the code rule', function (): void {
    $batch = $this->service->validate(
        csvUpload([importRow($this->alpha, 1, ['employee_number' => 'CSV-0001'])]),
        $this->admin,
    );

    $this->service->import($batch, $this->admin);

    expect(Employee::query()->firstOrFail()->employee_number)
        ->toBe('EMP-000001')
        ->not->toBe('CSV-0001');
});

it('does not let a legacy csv number collide with an existing employee number', function (): void {
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

    expect($batch->failed_rows)->toBe(0)
        ->and($batch->isImportable())->toBeTrue();

    $this->service->import($batch, $this->admin);

    expect(Employee::query()->where('employee_number', 'CSV-DUP')->count())->toBe(1)
        ->and(Employee::query()->where('employee_number', 'EMP-000001')->exists())->toBeTrue();
});

it('generates unique numbers when legacy rows contain the same employee number', function (): void {
    $batch = $this->service->validate(
        csvUpload([
            importRow($this->alpha, 1, ['employee_number' => 'CSV-SAME']),
            importRow($this->alpha, 2, ['employee_number' => 'CSV-SAME']),
        ]),
        $this->admin,
    );

    expect($batch->valid_rows)->toBe(2)
        ->and($batch->failed_rows)->toBe(0);

    $this->service->import($batch, $this->admin);

    expect(Employee::query()->orderBy('employee_number')->pluck('employee_number')->all())
        ->toBe(['EMP-000001', 'EMP-000002']);
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

    $template = $this->actingAs($this->admin)->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id]))->assertOk()->getContent();
    expect($template)->not->toContain($this->alpha['positions'][1]->id)
        ->and($template)->toContain($this->alpha['positions'][2]->id);

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

/*
 * The reader stops at MAX_ROWS. It used to stop silently, so an over-long file
 * produced a batch whose totals described only the part that was read.
 */
it('reports the rows it skipped past the row cap', function (): void {
    $service = app(EmployeeCsvImportService::class);
    $max = $service->maxRows();

    // Rows naming an organization that does not exist: each is rejected after
    // a single lookup, which keeps this file cheap to validate.
    $rows = array_fill(0, $max + 3, ['organization_code' => 'NO-SUCH-ORG', 'first_name' => 'Over', 'father_name' => 'Cap']);

    $batch = $service->validate(csvUpload($rows), $this->admin);

    expect($batch->total_rows)->toBe($max)
        ->and($service->skippedRowCount())->toBe(3);
});

it('warns on the import page when a file ran past the row cap', function (): void {
    $max = app(EmployeeCsvImportService::class)->maxRows();
    $rows = array_fill(0, $max + 2, ['organization_code' => 'NO-SUCH-ORG', 'first_name' => 'Over', 'father_name' => 'Cap']);

    $this->actingAs($this->admin)
        ->post(route('employees.import.store'), ['file' => csvUpload($rows)])
        ->assertRedirect(route('employees.import.create'));

    $this->actingAs($this->admin)
        ->get(route('employees.import.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('skippedRows', 2)->where('maxRows', $max));
});

/* The template's example row must line up with its own header. */
it('serves a template whose sample row has one value per column', function (): void {
    $body = $this->actingAs($this->admin)->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id]))->getContent();
    $lines = explode("\n", trim(ltrim($body, "\xEF\xBB\xBF")));

    expect(str_getcsv($lines[1]))->toHaveCount(count(EmployeeCsvImportService::templateColumns()));
});

it('downloads real placement names only for the selected organization', function (): void {
    $body = $this->actingAs($this->admin)->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id]))->assertOk()->getContent();
    $lines = explode("\n", trim(substr($body, 3)));
    expect($lines)->toHaveCount(3);
    $row = array_combine(EmployeeCsvImportService::templateColumns(), str_getcsv($lines[1]));
    expect($row['organization_name'])->toBe($this->alpha['org']->name_en)
        ->and($row['organization_unit_name'])->toBe($this->alpha['unit']->name_en)
        ->and($row['position_name'])->toBe($this->alpha['positions'][1]->title_en)
        ->and($row['position_reference'])->toBe($this->alpha['positions'][1]->id)
        ->and($row['first_name'])->toBe('')
        ->and($body)->not->toContain($this->beta['org']->code)
        ->and($body)->not->toContain('abebe@example.et');
});

it('requires an active accessible organization for template downloads', function (): void {
    $this->actingAs($this->admin)->get(route('employees.import.template'))->assertSessionHasErrors('organization_id');
    $this->alpha['org']->update(['status' => 'inactive']);
    $this->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id]))->assertNotFound();
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('employees.import.view', 'web'));
    $user->organizationScopes()->create(['organization_id' => $this->beta['org']->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->alpha['org']->update(['status' => 'active']);
    $this->actingAs($user)->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id]))->assertNotFound();
    $this->get(route('employees.import.template', ['organization_id' => $this->beta['org']->id]))->assertOk();
});

it('does not suggest inactive positions and keeps an organization starter when none remain', function (): void {
    foreach ($this->alpha['positions'] as $position) {
        $position->update(['is_active' => false]);
    }
    $body = $this->actingAs($this->admin)->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id]))->assertOk()->getContent();
    $lines = explode("\n", trim(substr($body, 3)));
    $row = array_combine(EmployeeCsvImportService::templateColumns(), str_getcsv($lines[1]));
    expect($lines)->toHaveCount(2)->and($row['organization_name'])->toBe($this->alpha['org']->name_en)->and($row['position_name'])->toBe('');
});

it('imports filled name templates and rejects mismatched names', function (): void {
    $this->alpha['positions'][2]->update(['title_en' => $this->alpha['positions'][1]->title_en]);
    $csv = $this->service->templateCsv($this->alpha['org']);
    $lines = explode("\n", trim(substr($csv, 3)));
    $row = array_combine(EmployeeCsvImportService::templateColumns(), str_getcsv($lines[1]));
    $row['first_name'] = 'Test';
    $row['father_name'] = 'Employee';
    $row['grandfather_name'] = 'Family';
    $row['gender'] = 'male';
    $upload = function (array $row): UploadedFile {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, EmployeeCsvImportService::templateColumns());
        fputcsv($stream, array_values($row));
        rewind($stream);
        $file = UploadedFile::fake()->createWithContent('names.csv', stream_get_contents($stream));
        fclose($stream);

        return $file;
    };
    $batch = $this->service->validate($upload($row), $this->admin);
    expect($batch->valid_rows)->toBe(1);
    $scoped = User::factory()->create();
    $scoped->organizationScopes()->create(['organization_id' => $this->beta['org']->id, 'scope_type' => 'self', 'is_active' => true]);
    expect($this->service->validate($upload($row), $scoped)->failed_rows)->toBe(1);
    $row['position_name'] = 'Wrong position';
    $invalid = $this->service->validate($upload($row), $this->admin);
    expect($invalid->failed_rows)->toBe(1);
    $result = $this->service->import($batch, $this->admin);
    expect($result['imported'])->toBe(1);
    expect(EmployeeAssignment::where('position_id', $this->alpha['positions'][1]->id)->exists())->toBeTrue();
    expect(EmployeeAssignment::where('position_id', $this->alpha['positions'][2]->id)->exists())->toBeFalse();
});

/*
 * Preview used to re-query the organization and the unit for every single row,
 * so a file repeating one organization cost three queries per row.
 */
it('looks the organization and unit up once when building a preview', function (): void {
    $ctx = importOrg('PRV');

    // Six rows: one shared organization and unit, a distinct vacant position
    // each, which is the shape of a real file.
    $rows = [];

    foreach (range(1, 6) as $n) {
        $position = Position::query()->create([
            'organization_id' => $ctx['org']->id,
            'organization_unit_id' => $ctx['unit']->id,
            'job_position_code' => 'PRV-EXTRA-'.$n,
            'title_en' => 'Preview Officer '.$n,
            'is_active' => true,
        ]);

        $rows[] = importRow($ctx, 1, [
            'position_code' => $position->job_position_code,
            'email' => "preview{$n}@example.et",
        ]);
    }

    $batch = app(EmployeeCsvImportService::class)->validate(csvUpload($rows), $this->admin);

    expect($batch->valid_rows)->toBe(6);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    app(EmployeeCsvImportService::class)->preview($batch);

    /*
     * One query to load the rows, one for the organization, one for the unit
     * and one position lookup per row: ten or so. Re-querying the organization
     * and unit per row would be nineteen.
     */
    expect($queries)->toBeGreaterThan(0)->toBeLessThanOrEqual(12);
});

it('builds the template name columns in the requested locale', function (): void {
    $this->alpha['org']->update(['name_am' => 'አልፋ ተቋም']);
    $this->alpha['unit']->update(['name_am' => 'አልፋ ክፍል']);
    $this->alpha['positions'][1]->update(['title_am' => 'አልፋ የሥራ መደብ']);

    $amharic = $this->actingAs($this->admin)
        ->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id, 'locale' => 'am']))
        ->assertOk()
        ->getContent();

    $lines = explode("\n", trim(substr($amharic, 3)));
    $row = array_combine(EmployeeCsvImportService::templateColumns(), str_getcsv($lines[1]));

    expect($row['organization_name'])->toBe('አልፋ ተቋም')
        ->and($row['organization_unit_name'])->toBe('አልፋ ክፍል')
        ->and($row['position_name'])->toBe('አልፋ የሥራ መደብ')
        // The header stays in canonical keys, or the file cannot be re-imported.
        ->and(str_getcsv($lines[0]))->toBe(EmployeeCsvImportService::templateColumns());

    $english = $this->actingAs($this->admin)
        ->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id, 'locale' => 'en']))
        ->assertOk()
        ->getContent();

    $englishRow = array_combine(
        EmployeeCsvImportService::templateColumns(),
        str_getcsv(explode("\n", trim(substr($english, 3)))[1]),
    );

    expect($englishRow['organization_name'])->toBe($this->alpha['org']->name_en);
});

it('falls back to the other language when a translation is missing', function (): void {
    // name_am is deliberately left unset on the seeded organization.
    $body = $this->actingAs($this->admin)
        ->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id, 'locale' => 'am']))
        ->assertOk()
        ->getContent();

    $row = array_combine(
        EmployeeCsvImportService::templateColumns(),
        str_getcsv(explode("\n", trim(substr($body, 3)))[1]),
    );

    expect($row['organization_name'])->toBe($this->alpha['org']->name_en);
});

it('follows the session locale when the template url names none', function (): void {
    $this->alpha['org']->update(['name_am' => 'አልፋ ተቋም']);

    $body = $this->actingAs($this->admin)
        ->withSession(['locale' => 'am'])
        ->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id]))
        ->assertOk()
        ->getContent();

    $row = array_combine(
        EmployeeCsvImportService::templateColumns(),
        str_getcsv(explode("\n", trim(substr($body, 3)))[1]),
    );

    expect($row['organization_name'])->toBe('አልፋ ተቋም');
});

it('rejects an unsupported template locale', function (): void {
    $this->actingAs($this->admin)
        ->get(route('employees.import.template', ['organization_id' => $this->alpha['org']->id, 'locale' => 'fr']))
        ->assertSessionHasErrors('locale');
});
