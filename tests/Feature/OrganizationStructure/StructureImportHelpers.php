<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use App\Models\GradeLevel;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Models\User;
use App\Services\OrganizationStructureImport\StructureSheet;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Build a real .xlsx on disk from a sheet => rows map and wrap it in an
 * UploadedFile, so the tests exercise the actual PhpSpreadsheet reader rather
 * than a stubbed parser.
 *
 * @param  array<string, array<int, array<int, string|null>>>  $sheets  title => rows (first row = header)
 */
function structureWorkbookFile(array $sheets, string $name = 'structure.xlsx'): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $title => $rows) {
        $worksheet = $spreadsheet->createSheet();
        $worksheet->setTitle($title);

        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $worksheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'struct').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** Header rows for every sheet, taken straight from the enum. */
function structureHeaders(StructureSheet $sheet): array
{
    return $sheet->knownColumns();
}

/**
 * A complete, valid workbook for a brand-new organization. Callers override
 * individual sheets to build the failure cases.
 */
function validStructureSheets(array $overrides = []): array
{
    $sheets = [
        StructureSheet::Organization->value => [
            structureHeaders(StructureSheet::Organization),
            // code, name_en, name_am, type_code, parent_code, status
            ['IMP-ORG', 'Import Bureau', 'የማስመጣት ቢሮ', 'IMPTYPE', null, 'active'],
        ],
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            // unit_code, name_en, name_am, type_code, parent_unit, host_org, owner_org, status
            ['U-ROOT', 'Head Office', null, 'IMPUNIT', null, null, null, 'active'],
            ['U-CHILD', 'Finance Department', null, 'IMPUNIT', 'U-ROOT', null, null, 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            // position_code, old_code, standard_name, bpr_name, unit_code, grade, status, profession, slots
            ['P-001', null, 'Finance Officer', null, 'U-CHILD', 'Grade A', 'active', 'IMPOCC', '1'],
            [null, null, 'Auditor', null, 'U-ROOT', null, 'active', null, '2'],
        ],
        StructureSheet::Employees->value => [
            structureHeaders(StructureSheet::Employees),
            // number, first, father, grandfather, gender, phone, email, position_code, start, status
            [null, 'Abebe', 'Kebede', 'Tesfaye', 'male', null, 'abebe@example.test', 'P-001', '2026-01-01', 'active'],
        ],
    ];

    foreach ($overrides as $sheet => $rows) {
        if ($rows === null) {
            unset($sheets[$sheet]);

            continue;
        }

        $sheets[$sheet] = $rows;
    }

    return $sheets;
}

function seedStructureReferenceData(): void
{
    OrganizationType::query()->firstOrCreate(
        ['code' => 'IMPTYPE'],
        ['name_en' => 'Import Test Type', 'is_active' => true],
    );

    OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'IMPUNIT'],
        ['name_en' => 'Import Test Unit Type', 'is_active' => true, 'sort_order' => 0],
    );

    GradeLevel::query()->firstOrCreate(['name' => 'Grade A']);

    Occupation::query()->firstOrCreate(
        ['code' => 'IMPOCC'],
        ['name_en' => 'Import Test Occupation', 'is_active' => true],
    );

    // A code rule for every entity the importer can generate a code for. Blank
    // code cells are only legal when the matching rule exists.
    $rules = [
        [CodeRuleEntityType::Organization, 'Organization Code', 'ORG'],
        [CodeRuleEntityType::OrganizationUnit, 'Organization Unit Code', 'UNIT'],
        [CodeRuleEntityType::Position, 'Position Code', 'POS'],
        [CodeRuleEntityType::Employee, 'Employee Number', 'EMP'],
    ];

    foreach ($rules as [$entityType, $name, $prefix]) {
        CodeRule::query()->firstOrCreate(
            ['entity_type' => $entityType->value, 'scope_type' => null, 'scope_id' => null],
            [
                'name_en' => $name,
                'prefix' => $prefix,
                'format' => '{PREFIX}-{SEQUENCE}',
                'separator' => '-',
                'sequence_length' => 4,
                'next_number' => 1,
                'reset_frequency' => CodeRuleResetFrequency::Never,
                'is_active' => true,
                'allow_manual_override' => true,
                'require_approval_for_override' => false,
                'active_scope_key' => CodeRule::buildActiveScopeKey($entityType),
            ],
        );
    }
}

function structureImporterUser(): User
{
    foreach (['organizations.view', 'organizations.manage', 'organizations.import'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('StructureImporter', 'web');
    $role->syncPermissions(['organizations.view', 'organizations.manage', 'organizations.import']);

    $user = User::factory()->create();
    $user->assignRole('StructureImporter');

    return $user;
}

/** A user who may manage organizations but has no import permission. */
function structureNonImporterUser(): User
{
    foreach (['organizations.view', 'organizations.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('StructureViewer', 'web');
    $role->syncPermissions(['organizations.view', 'organizations.manage']);

    $user = User::factory()->create();
    $user->assignRole('StructureViewer');

    return $user;
}

/**
 * POST a workbook to the preview endpoint and follow the redirect back to the
 * wizard, returning the rendered preview props.
 *
 * The preview endpoint is POST-only and redirects to the wizard's GET URL with
 * the result flashed to the session — that is what keeps the address bar on a
 * refreshable URL. Tests therefore have to follow the redirect to see the page.
 *
 * @param  array<string, mixed>  $extra  extra form fields (e.g. auto_generate_codes)
 * @return array<string, mixed> the `preview` prop
 */
function previewProps(array $sheets, User $user, array $extra = []): array
{
    $props = [];

    test()->actingAs($user)
        ->post(route('organizations.import-structure.preview'), [
            'file' => structureWorkbookFile($sheets),
        ] + $extra)
        ->assertRedirect(route('organizations.import-structure.create'));

    test()->actingAs($user)
        ->get(route('organizations.import-structure.create'))
        ->assertInertia(function ($page) use (&$props): void {
            $props = $page->toArray()['props']['preview'] ?? [];
        });

    return $props;
}

/**
 * The flat list of error messages the preview reports, regardless of sheet.
 *
 * @return list<string>
 */
function previewErrorMessages(array $sheets, User $user): array
{
    return collect(previewProps($sheets, $user)['errors'] ?? [])
        ->flatten(1)
        ->pluck('message')
        ->all();
}
