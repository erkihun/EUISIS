<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\DesignImportBatch;
use App\Models\DesignImportRow;
use App\Models\InstitutionMandate;
use App\Models\LegacyCodeMapping;
use App\Models\LegalInstrumentType;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationDesign\Import\DesignImportReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Staged design imports (docs/organization-design-legacy-baseline-plan.md,
 * ADR-LB-004): upload → parse → validate → map → commit through the domain
 * services; codes match instead of duplicating; a re-run creates nothing
 * twice; uploading and committing are separate grants; the scope holds.
 */

beforeEach(function (): void {
    foreach (array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name') as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('DI Uploader', 'web')->syncPermissions(['design-imports.viewAny', 'design-imports.create']);
    Role::findOrCreate('DI Committer', 'web')->syncPermissions(['design-imports.viewAny', 'design-imports.commit']);
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'name_am' => 'አዋጅ', 'sort_order' => 10]);
    Storage::fake('local');
});

function diOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'DI-BUREAU'], ['name_en' => 'DI Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'DI-'.$suffix.'-'.uniqid(), 'name_en' => 'DI Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function diUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function diCsv(array $header, array $rows): UploadedFile
{
    $lines = [implode(',', $header)];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => str_contains((string) $v, ',') ? '"'.$v.'"' : (string) $v, $row));
    }

    return UploadedFile::fake()->createWithContent('legacy.csv', "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n");
}

test('a mandate file is staged, checked row by row, and committed into draft mandates; an existing code is matched, not duplicated', function (): void {
    $organization = diOrganization('A');
    $uploader = diUser($organization, 'DI Uploader');
    $committer = diUser($organization, 'DI Committer');
    $existing = InstitutionMandate::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-OLD', 'title_en' => 'Existing', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'), 'legal_instrument_number' => '1/2010', 'effective_from' => '2010-01-01', 'status' => OrganizationDesignStatus::Published]);

    $file = diCsv(['code', 'title_en', 'legal_instrument_type_code', 'legal_instrument_number', 'effective_from', 'legacy_code'], [
        ['MND-NEW', 'Legacy mandate', 'PROCLAMATION', '12/2015', '2015-03-01', 'OLD-77'],
        ['MND-OLD', 'Existing mandate again', 'PROCLAMATION', '1/2010', '2010-01-01', ''],
        ['MND-BAD', 'Bad date', 'PROCLAMATION', '3/2015', '01/03/2015', ''],
        ['MND-NEW', 'Duplicate row', 'PROCLAMATION', '12/2015', '2015-03-01', ''],
        ['', 'No code', 'PROCLAMATION', '4/2015', '2015-03-01', ''],
    ]);

    $this->actingAs($uploader)->post(route('organization-design.imports.upload'), ['organization_id' => $organization->id, 'entity_type' => 'mandates', 'file' => $file])->assertSessionHasNoErrors()->assertRedirect();
    $batch = DesignImportBatch::query()->firstOrFail();
    // The queue runs synchronously in tests: the batch is already parsed and checked.
    expect($batch->status)->toBe(DesignImportBatch::STATUS_VALIDATED)
        ->and($batch->total_rows)->toBe(5)->and($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(3)
        ->and(InstitutionMandate::query()->where('code', 'MND-NEW')->exists())->toBeFalse();

    $rows = $batch->rows()->get()->keyBy('row_number');
    expect($rows[3]->matched_id)->toBe($existing->id)->and($rows[3]->match_source)->toBe('code')
        ->and(collect($rows[4]->errors)->pluck('field'))->toContain('effective_from')
        ->and(collect($rows[5]->errors)->pluck('message')->implode(' '))->toContain('MND-NEW')
        ->and(collect($rows[6]->errors)->pluck('field'))->toContain('code');

    // Uploading does not grant committing; committing is the other seat.
    $this->actingAs($uploader)->post(route('organization-design.imports.commit', $batch))->assertForbidden();
    $this->actingAs($committer)->post(route('organization-design.imports.commit', $batch))->assertSessionHasNoErrors();

    $batch->refresh();
    $created = InstitutionMandate::query()->where('organization_id', $organization->id)->where('code', 'MND-NEW')->get();
    expect($batch->status)->toBe(DesignImportBatch::STATUS_COMMITTED)->and($batch->committed_rows)->toBe(1)->and($batch->matched_rows)->toBe(1)
        ->and($created)->toHaveCount(1)->and($created->first()->status)->toBe(OrganizationDesignStatus::Draft)
        ->and(InstitutionMandate::query()->where('code', 'MND-OLD')->count())->toBe(1)
        ->and(LegacyCodeMapping::query()->where('legacy_code', 'OLD-77')->value('canonical_id'))->toBe($created->first()->id);

    // The same file again creates nothing new: the code now matches.
    $this->actingAs($uploader)->post(route('organization-design.imports.upload'), ['organization_id' => $organization->id, 'entity_type' => 'mandates', 'file' => diCsv(['code', 'title_en', 'legal_instrument_type_code', 'legal_instrument_number', 'effective_from', 'legacy_code'], [['MND-NEW', 'Legacy mandate', 'PROCLAMATION', '12/2015', '2015-03-01', 'OLD-77']])]);
    // Both batches can share a timestamp; the reference number is strictly increasing.
    $second = DesignImportBatch::query()->orderByDesc('reference_number')->firstOrFail();
    $this->actingAs($committer)->post(route('organization-design.imports.commit', $second))->assertSessionHasNoErrors();
    expect(InstitutionMandate::query()->where('code', 'MND-NEW')->count())->toBe(1)->and($second->fresh()->matched_rows)->toBe(1);
});

test('an unknown reference waits for a person to map it; the mapping is remembered and the row then commits', function (): void {
    $organization = diOrganization('B');
    $uploader = diUser($organization, 'DI Uploader');
    $committer = diUser($organization, 'DI Committer');
    $mandate = InstitutionMandate::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-1', 'title_en' => 'Mandate', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'), 'legal_instrument_number' => '1/2010', 'effective_from' => '2010-01-01', 'status' => OrganizationDesignStatus::Published]);

    $this->actingAs($uploader)->post(route('organization-design.imports.upload'), ['organization_id' => $organization->id, 'entity_type' => 'functions', 'file' => diCsv(['code', 'title_en', 'mandate_code'], [['F-1', 'Licensing', 'OLD-MANDATE-9']])]);
    $batch = DesignImportBatch::query()->firstOrFail();
    $row = $batch->rows()->firstOrFail();
    expect($batch->status)->toBe(DesignImportBatch::STATUS_MAPPING_REQUIRED)->and($row->status)->toBe(DesignImportRow::STATUS_MAPPING_REQUIRED);
    $this->actingAs($committer)->post(route('organization-design.imports.commit', $batch))->assertSessionHasErrors('status');

    // A record of another organization cannot be mapped in.
    $foreign = InstitutionMandate::query()->create(['organization_id' => diOrganization('C')->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-X', 'title_en' => 'Foreign', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'), 'legal_instrument_number' => '9/2010', 'effective_from' => '2010-01-01', 'status' => OrganizationDesignStatus::Published]);
    $this->actingAs($uploader)->put(route('organization-design.imports.rows.map', [$batch, $row]), ['column' => 'mandate_code', 'canonical_id' => $foreign->id])->assertSessionHasErrors('canonical_id');

    $this->actingAs($uploader)->put(route('organization-design.imports.rows.map', [$batch, $row]), ['column' => 'mandate_code', 'canonical_id' => $mandate->id])->assertSessionHasNoErrors();
    expect($row->fresh()->status)->toBe(DesignImportRow::STATUS_VALID)->and($batch->fresh()->status)->toBe(DesignImportBatch::STATUS_VALIDATED)
        ->and(LegacyCodeMapping::query()->where('legacy_code', 'OLD-MANDATE-9')->value('canonical_id'))->toBe($mandate->id);

    $this->actingAs($committer)->post(route('organization-design.imports.commit', $batch))->assertSessionHasNoErrors();
    expect(OrganizationFunction::query()->where('organization_id', $organization->id)->where('code', 'F-1')->value('mandate_id'))->toBe($mandate->id);
});

test('an Excel workbook is read in chunks, Excel dates are understood, and blank rows are skipped', function (): void {
    $organization = diOrganization('X');
    $uploader = diUser($organization, 'DI Uploader');
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['code', 'title_en', 'legal_instrument_type_code', 'legal_instrument_number', 'effective_from'], null, 'A1');
    // More rows than one read chunk, an Excel serial date, and a blank row in the middle.
    for ($i = 2; $i <= DesignImportReader::CHUNK + 30; $i++) {
        if ($i === 10) {
            continue;
        }
        $sheet->fromArray(['XL-'.$i, 'Mandate '.$i, 'PROCLAMATION', $i.'/2015', $i === 2 ? 42064 : '2015-03-01'], null, 'A'.$i);
    }
    $path = sys_get_temp_dir().'/design-import-'.uniqid().'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $file = new UploadedFile($path, 'legacy.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $this->actingAs($uploader)->post(route('organization-design.imports.upload'), ['organization_id' => $organization->id, 'entity_type' => 'mandates', 'file' => $file])->assertSessionHasNoErrors();
    $batch = DesignImportBatch::query()->firstOrFail();
    $expected = DesignImportReader::CHUNK + 28;
    expect($batch->status)->toBe(DesignImportBatch::STATUS_VALIDATED)->and($batch->total_rows)->toBe($expected)->and($batch->valid_rows)->toBe($expected)
        ->and($batch->rows()->where('row_number', 2)->first()->data['effective_from'])->toBe('2015-03-01');
    @unlink($path);
});

test('the reader works on a disk without local paths (object storage in a multi-node setup) and leaves no temporary copy', function (): void {
    // A custom driver stands in for S3: it is not the "local" driver, so the reader must stream the file.
    $root = storage_path('framework/testing/import-remote-'.uniqid());
    Storage::extend('remote-test', fn ($app, array $config) => Storage::createLocalDriver($config));
    config(['filesystems.disks.import-base' => ['driver' => 'local', 'root' => $root],
        'filesystems.disks.import-remote' => ['driver' => 'remote-test', 'root' => $root]]);
    Storage::disk('import-remote')->put('batch.csv', "code,title_en\nM-1,First\nM-2,Second\n");
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'import-copy-*') ?: [];

    $reader = new DesignImportReader;
    $rows = iterator_to_array($reader->rows('import-remote', 'batch.csv', 'csv', ['code', 'title_en']), false);

    expect($reader->headers('import-remote', 'batch.csv', 'csv'))->toBe(['code', 'title_en'])
        ->and(array_column(array_column($rows, 'data'), 'code'))->toBe(['M-1', 'M-2'])
        ->and(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'import-copy-*') ?: [])->toBe($before);
    Storage::disk('import-base')->deleteDirectory('');
});

test('a file missing a required column fails cleanly; an executable is refused; another organization sees nothing; templates download bilingual', function (): void {
    $organization = diOrganization('D');
    $uploader = diUser($organization, 'DI Uploader');

    $this->actingAs($uploader)->post(route('organization-design.imports.upload'), ['organization_id' => $organization->id, 'entity_type' => 'mandates', 'file' => diCsv(['code', 'title_en'], [['M-1', 'No instrument']])]);
    $batch = DesignImportBatch::query()->firstOrFail();
    expect($batch->status)->toBe(DesignImportBatch::STATUS_FAILED)->and($batch->error_summary['missing_columns'])->toContain('legal_instrument_type_code', 'effective_from');

    $this->actingAs($uploader)->post(route('organization-design.imports.upload'), ['organization_id' => $organization->id, 'entity_type' => 'mandates', 'file' => UploadedFile::fake()->create('macro.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('file');
    $this->actingAs($uploader)->post(route('organization-design.imports.upload'), ['organization_id' => diOrganization('E')->id, 'entity_type' => 'mandates', 'file' => diCsv(['code'], [['X']])])->assertForbidden();

    $other = diUser(diOrganization('F'), 'DI Uploader');
    $this->actingAs($other)->get(route('organization-design.imports.show', $batch))->assertForbidden();
    $this->actingAs($other)->get(route('organization-design.imports.index'))->assertOk()->assertDontSee($batch->reference_number);

    $en = $this->actingAs($uploader)->get(route('organization-design.imports.template', ['entityType' => 'services', 'locale' => 'en']));
    $en->assertOk();
    expect($en->getContent())->toContain('code,name_en,name_am')->toContain('Name (English)');
    $am = $this->actingAs($uploader)->get(route('organization-design.imports.template', ['entityType' => 'services', 'locale' => 'am']));
    expect($am->getContent())->toContain('ስም (እንግሊዝኛ)');
});
