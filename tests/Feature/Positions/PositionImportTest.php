<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\OrganizationScopeType;
use App\Models\CodeRule;
use App\Models\GradeLevel;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionImportBatch;
use App\Models\PositionImportBatchRow;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Positions\PositionImportFileException;
use App\Services\Positions\PositionImportService;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** An active organization with one active unit. */
function positionImportOrg(string $prefix): array
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'PIMP-TYPE'],
        ['name_en' => 'Position Import Type'],
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

    return compact('org', 'unit');
}

/** A well-formed row for the given organization and unit. */
function positionImportRow(array $ctx, array $overrides = []): array
{
    return array_merge([
        'organization_code' => $ctx['org']->code,
        'organization_name' => $ctx['org']->name_en,
        'organization_unit_code' => $ctx['unit']->code,
        'organization_unit_name' => $ctx['unit']->name_en,
        'title_en' => 'Accountant',
        'title_am' => 'የሂሳብ ባለሙያ',
        'occupation_code' => '2411',
        'grade_level' => 'X',
        'job_family' => 'Finance',
        'old_code' => '',
        'bpr_name' => '',
        'is_unit_head' => '',
    ], $overrides);
}

/** Build a CSV upload from row arrays keyed by column name. */
function positionImportCsv(array $rows, ?array $columns = null): UploadedFile
{
    $columns ??= PositionImportService::COLUMNS;
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $columns, ',', '"', '');

    foreach ($rows as $row) {
        fputcsv($stream, array_map(static fn (string $column): string => (string) ($row[$column] ?? ''), $columns), ',', '"', '');
    }

    rewind($stream);
    $path = tempnam(sys_get_temp_dir(), 'pimp').'.csv';
    file_put_contents($path, stream_get_contents($stream));
    fclose($stream);

    return new UploadedFile($path, 'positions.csv', 'text/csv', null, true);
}

beforeEach(function (): void {
    app()->setLocale('en');

    foreach (['positions.view', 'positions.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Employee', 'web');

    CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::Position->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Position Code',
        'prefix' => 'POS',
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
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Position),
    ]);

    Occupation::query()->create(['code' => '2411', 'isco_code' => '2411', 'name_en' => 'Accountants', 'is_active' => true]);
    GradeLevel::query()->create(['name' => 'X']);

    $this->alpha = positionImportOrg('PALPHA');
    $this->beta = positionImportOrg('PBETA');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');

    $this->service = app(PositionImportService::class);
});

it('checks a file without creating any position', function (): void {
    $before = Position::query()->count();

    $batch = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha),
        positionImportRow($this->alpha, ['title_en' => 'Cashier', 'organization_unit_code' => '', 'organization_unit_name' => '']),
        // A template row nothing was typed into.
        positionImportRow($this->alpha, ['title_en' => '', 'title_am' => '', 'occupation_code' => '', 'grade_level' => '', 'job_family' => '']),
    ]), $this->admin);

    expect($batch->status)->toBe(PositionImportBatch::STATUS_VALIDATED)
        ->and($batch->valid_rows)->toBe(2)
        ->and($batch->failed_rows)->toBe(0)
        ->and($batch->unfilledRows())->toBe(1)
        ->and(Position::query()->count())->toBe($before);
});

it('creates the positions on confirmation with generated codes', function (): void {
    $batch = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha, ['is_unit_head' => 'yes']),
        positionImportRow($this->alpha, ['title_en' => 'Cashier']),
        positionImportRow($this->alpha, ['title_en' => 'Director', 'organization_unit_code' => '']),
    ]), $this->admin);

    $result = $this->service->import($batch, $this->admin);

    expect($result['imported'])->toBe(3)
        ->and($batch->refresh()->status)->toBe(PositionImportBatch::STATUS_IMPORTED);

    $positions = Position::query()->where('organization_id', $this->alpha['org']->id)->orderBy('title_en')->get();

    expect($positions)->toHaveCount(3)
        ->and($positions->pluck('job_position_code')->filter()->unique())->toHaveCount(3)
        ->and($positions->firstWhere('title_en', 'Accountant')->is_unit_head)->toBeTrue()
        ->and($positions->firstWhere('title_en', 'Accountant')->organization_unit_id)->toBe($this->alpha['unit']->id)
        ->and($positions->firstWhere('title_en', 'Accountant')->grade_level)->toBe('X')
        ->and($positions->firstWhere('title_en', 'Director')->organization_unit_id)->toBeNull()
        ->and(PositionImportBatchRow::query()->where('batch_id', $batch->id)->whereNotNull('position_id')->count())->toBe(3);
});

it('reports each problem on its row and still imports the good rows', function (): void {
    $batch = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha),
        positionImportRow($this->alpha, ['title_am' => '']),
        positionImportRow($this->alpha, ['occupation_code' => '9999']),
        positionImportRow($this->alpha, ['grade_level' => 'No such grade']),
        positionImportRow($this->alpha, ['organization_unit_code' => $this->beta['unit']->code]),
        positionImportRow($this->alpha, ['is_unit_head' => 'maybe']),
        positionImportRow($this->alpha, ['organization_code' => 'NOPE']),
    ]), $this->admin);

    expect($batch->valid_rows)->toBe(1)->and($batch->failed_rows)->toBe(6);

    $errors = $batch->rows()->orderBy('row_number')->get()->mapWithKeys(
        fn (PositionImportBatchRow $row): array => [$row->row_number => implode(' ', $row->errors ?? [])],
    );

    expect($errors[3])->toContain('title_am')
        ->and($errors[4])->toContain('occupation_code')
        ->and($errors[5])->toContain('grade_level')
        ->and($errors[6])->toContain('organization_unit_code')
        ->and($errors[7])->toContain('is_unit_head')
        ->and($errors[8])->toContain('organization_code');

    expect($this->service->import($batch, $this->admin)['imported'])->toBe(1)
        ->and($this->service->failedRowsCsv($batch))->toContain('errors')->toContain('9999');
});

it('allows one head position per unit across the file and the database', function (): void {
    $batch = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha, ['is_unit_head' => 'yes']),
        positionImportRow($this->alpha, ['title_en' => 'Second head', 'is_unit_head' => 'yes']),
    ]), $this->admin);

    expect($batch->valid_rows)->toBe(1)->and($batch->failed_rows)->toBe(1);

    $this->service->import($batch, $this->admin);

    $again = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha, ['title_en' => 'Third head', 'is_unit_head' => 'yes']),
    ]), $this->admin);

    expect($again->valid_rows)->toBe(0)
        ->and($again->status)->toBe(PositionImportBatch::STATUS_FAILED);
});

it('refuses a row for an organization outside the importer scope', function (): void {
    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $batch = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha),
        positionImportRow($this->beta),
    ]), $scoped);

    expect($batch->valid_rows)->toBe(1)
        ->and($batch->failed_rows)->toBe(1)
        ->and(implode(' ', $batch->rows()->where('row_number', 3)->first()->errors))->toContain('outside your organization scope');
});

it('refuses a file without the required columns', function (): void {
    $this->service->validate(positionImportCsv([['title_en' => 'Accountant']], ['title_en']), $this->admin);
})->throws(PositionImportFileException::class);

it('hands out a template that can be uploaded back', function (): void {
    $csv = $this->service->templateCsv($this->alpha['org']);

    expect($csv)->toContain(implode(',', PositionImportService::COLUMNS))
        ->toContain($this->alpha['unit']->code);

    $path = tempnam(sys_get_temp_dir(), 'pimp').'.xlsx';
    file_put_contents($path, $this->service->templateXlsx($this->alpha['org']));
    $workbook = IOFactory::load($path);

    expect($workbook->getSheetNames())->toBe(['positions', 'occupations', 'grade_levels'])
        ->and($workbook->getSheet(1)->getCell('A2')->getValue())->toBe('2411');

    // Fill in the unit's row and upload the workbook itself.
    $sheet = $workbook->getSheet(0);
    $sheet->setCellValue('E3', 'Auditor');
    $sheet->setCellValue('F3', 'ኦዲተር');
    $sheet->setCellValue('G3', 2411);
    IOFactory::createWriter($workbook, 'Xlsx')->save($path);

    $batch = $this->service->validate(new UploadedFile($path, 'positions.xlsx', null, null, true), $this->admin);

    expect($batch->valid_rows)->toBe(1)
        ->and($batch->failed_rows)->toBe(0)
        ->and($batch->unfilledRows())->toBe(1);
});

it('walks the three steps over HTTP', function (): void {
    $this->actingAs($this->admin)
        ->get(route('positions.import.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Positions/Import')->where('batch', null));

    $this->actingAs($this->admin)
        ->post(route('positions.import.store'), ['file' => positionImportCsv([positionImportRow($this->alpha)])])
        ->assertRedirect(route('positions.import.create'));

    $batch = PositionImportBatch::query()->latest()->firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('positions.import.create'))
        ->assertInertia(fn (Assert $page) => $page->where('batch.id', $batch->id)->where('batch.valid_rows', 1)->has('rows', 1));

    $this->actingAs($this->admin)
        ->post(route('positions.import.confirm', $batch))
        ->assertRedirect(route('positions.import.create'));

    expect(Position::query()->where('organization_id', $this->alpha['org']->id)->where('title_en', 'Accountant')->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->get(route('positions.import.template', ['organization_id' => $this->alpha['org']->id, 'format' => 'xlsx']))
        ->assertOk();
});

it('imports a large file over several passes and can be continued', function (): void {
    $batch = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha, ['title_en' => 'First']),
        positionImportRow($this->alpha, ['title_en' => 'Second']),
        positionImportRow($this->alpha, ['title_en' => 'Third']),
    ]), $this->admin);

    // No time at all: each pass still handles one row, so the batch always moves forward.
    $pass = $this->service->import($batch, $this->admin, null, 0.0);

    expect($pass['imported'])->toBe(1)
        ->and($pass['remaining'])->toBe(2)
        ->and($batch->refresh()->status)->toBe(PositionImportBatch::STATUS_VALIDATED)
        ->and($batch->isInProgress())->toBeTrue()
        ->and($batch->isFinished())->toBeFalse()
        ->and($batch->isImportable())->toBeTrue();

    $this->service->import($batch, $this->admin, null, 0.0);
    $last = $this->service->import($batch, $this->admin, null, 0.0);

    expect($last['remaining'])->toBe(0)
        ->and($last['total_imported'])->toBe(3)
        ->and($batch->refresh()->status)->toBe(PositionImportBatch::STATUS_IMPORTED)
        ->and($batch->isFinished())->toBeTrue()
        ->and(Position::query()->where('organization_id', $this->alpha['org']->id)->pluck('title_en')->sort()->values()->all())
        ->toBe(['First', 'Second', 'Third']);

    // A finished batch is never imported a second time.
    expect(fn () => $this->service->import($batch, $this->admin))->toThrow(RuntimeException::class);
    expect(Position::query()->where('organization_id', $this->alpha['org']->id)->count())->toBe(3);
});

it('shows a finished batch again instead of importing it twice', function (): void {
    $batch = $this->service->validate(positionImportCsv([positionImportRow($this->alpha)]), $this->admin);

    $this->actingAs($this->admin)->post(route('positions.import.confirm', $batch))->assertRedirect(route('positions.import.create'));
    $this->actingAs($this->admin)->post(route('positions.import.confirm', $batch))
        ->assertRedirect(route('positions.import.create'))
        ->assertSessionMissing('flash');

    expect(Position::query()->where('organization_id', $this->alpha['org']->id)->count())->toBe(1);

    $this->actingAs($this->admin)
        ->get(route('positions.import.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('batch.finished', true)
            ->where('batch.in_progress', false)
            ->where('batch.imported_rows', 1)
            ->where('batch.remaining_rows', 0)
            ->where('rows.0.status', 'imported')
            ->whereNot('rows.0.position_code', null));
});

it('refuses a row for an organization that is not active', function (): void {
    $this->beta['org']->forceFill(['status' => 'dissolved'])->save();

    $batch = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha),
        positionImportRow($this->beta),
    ]), $this->admin);

    expect($batch->valid_rows)->toBe(1)
        ->and($batch->failed_rows)->toBe(1)
        ->and(implode(' ', $batch->rows()->where('row_number', 3)->first()->errors))->toContain('is not active');
});

it('marks a ready row whose title the unit already has', function (): void {
    $first = $this->service->validate(positionImportCsv([positionImportRow($this->alpha)]), $this->admin);
    $this->service->import($first, $this->admin);

    // The same file again, plus a title the unit does not have yet.
    $again = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha),
        positionImportRow($this->alpha, ['title_en' => 'Cashier', 'title_am' => 'ገንዘብ ተቀባይ']),
    ]), $this->admin);

    $rows = collect($this->service->preview($again))->keyBy('row_number');

    // Still importable: several positions of one title in a unit are allowed.
    expect($again->valid_rows)->toBe(2)
        ->and($rows[2]['already_exists'])->toBeTrue()
        ->and($rows[3]['already_exists'])->toBeFalse();
});

it('names the template after the organization code, also when it has no ASCII spelling', function (): void {
    $this->alpha['org']->forceFill(['code' => 'ማ-001'])->save();
    $this->beta['org']->forceFill(['code' => 'ከአ-001'])->save();

    $names = collect([$this->alpha, $this->beta])->map(fn (array $ctx): string => (string) $this->actingAs($this->admin)
        ->get(route('positions.import.template', ['organization_id' => $ctx['org']->id, 'format' => 'xlsx', 'locale' => 'en']))
        ->assertOk()
        ->headers->get('Content-Disposition'));

    expect($names[0])->not->toBe($names[1])
        ->and($names[0])->toContain("filename*=utf-8''".rawurlencode('position-import-ማ-001-template-en.xlsx'))
        ->and($names[1])->toContain("filename*=utf-8''".rawurlencode('position-import-ከአ-001-template-en.xlsx'));
});

it('reads back the rows-to-fix file with its formula guard removed', function (): void {
    $batch = $this->service->validate(positionImportCsv([
        positionImportRow($this->alpha, ['title_en' => '-Deputy', 'occupation_code' => '9999']),
    ]), $this->admin);

    $csv = $this->service->failedRowsCsv($batch);
    expect($csv)->toContain("'-Deputy");

    $path = tempnam(sys_get_temp_dir(), 'pimp').'.csv';
    file_put_contents($path, str_replace('9999', '2411', $csv));

    $fixed = $this->service->validate(new UploadedFile($path, 'fixed.csv', 'text/csv', null, true), $this->admin);

    expect($fixed->valid_rows)->toBe(1)
        ->and($fixed->rows()->first()->row_data['title_en'])->toBe('-Deputy');
});

it('is closed to a user who may not create positions and to another user\'s batch', function (): void {
    $employee = User::factory()->create();
    $employee->assignRole('Employee');

    $this->actingAs($employee)->get(route('positions.import.create'))->assertForbidden();
    $this->actingAs($employee)->post(route('positions.import.store'), ['file' => positionImportCsv([positionImportRow($this->alpha)])])->assertForbidden();

    $batch = $this->service->validate(positionImportCsv([positionImportRow($this->alpha)]), $this->admin);

    $other = User::factory()->create();
    $other->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $other->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->actingAs($other)->post(route('positions.import.confirm', $batch))->assertForbidden();
    $this->actingAs($other)->get(route('positions.import.errors', $batch))->assertForbidden();
});
