<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\OrganizationStatus;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationStructureImport\StructureSheet;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/StructureImportHelpers.php';

/**
 * Code generation during Organization Structure import.
 *
 * A blank code column is a *request to generate*, not a mistake: the importer
 * feeds the existing Code Rule engine and shows the resulting codes in the
 * preview before anything is written.
 *
 * Helpers (structureWorkbookFile, validStructureSheets, structureImporterUser, …)
 * live in OrganizationStructureImportTest.php — Pest loads both files, so they
 * are shared.
 */
beforeEach(function (): void {
    app()->setLocale('en');
    seedStructureReferenceData();
});

/** Re-point the code rules at formats that make the generated codes easy to assert. */
function useCodeRuleFormat(CodeRuleEntityType $entity, string $format, ?string $prefix = null): void
{
    CodeRule::query()
        ->where('entity_type', $entity->value)
        ->update(array_filter([
            'format' => $format,
            'prefix' => $prefix,
        ], static fn (mixed $v): bool => $v !== null));
}

/** Every code the preview settled, keyed as "Sheet:row". */
function previewCodes(array $sheets, User $user, array $extra = []): array
{
    $codes = [];

    foreach (previewProps($sheets, $user, $extra)['codes'] ?? [] as $entry) {
        $codes[$entry['sheet'].':'.$entry['row']] = $entry;
    }

    return $codes;
}

/**
 * A workbook with every code column left blank. `$overrides` replaces whole
 * sheets — note the caller's entries must win, so they are merged *last*.
 */
function blankCodeSheets(array $overrides = []): array
{
    return validStructureSheets(array_merge([
        StructureSheet::Organization->value => [
            structureHeaders(StructureSheet::Organization),
            // organization_code intentionally blank →  generated
            [null, 'Import Bureau', 'የማስመጣት ቢሮ', 'IMPTYPE', null, 'active'],
        ],
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            // unit_code blank → generated
            [null, 'Head Office', null, 'IMPUNIT', null, null, null, 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            // position_code blank → generated
            [null, null, 'Finance Officer', null, null, null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => null,
    ], $overrides));
}

// ── 1. Organization code ─────────────────────────────────────────────────────

it('generates the organization code from the code rule when the cell is blank', function (): void {
    // {ORG_TYPE_PREFIX}-{SEQUENCE} — the format the brief calls out.
    OrganizationType::query()->where('code', 'IMPTYPE')->update(['prefix' => 'MA']);
    useCodeRuleFormat(CodeRuleEntityType::Organization, '{ORG_TYPE_PREFIX}-{SEQUENCE}');

    $codes = previewCodes(blankCodeSheets(), structureImporterUser());

    $organization = $codes['Organization:2'];

    expect($organization['provided_code'])->toBeNull()
        ->and($organization['source'])->toBe('generated_by_code_rule')
        ->and($organization['generated_code'])->toMatch('/^MA-\d+$/');
});

it('imports the organization under its generated code', function (): void {
    OrganizationType::query()->where('code', 'IMPTYPE')->update(['prefix' => 'MA']);
    useCodeRuleFormat(CodeRuleEntityType::Organization, '{ORG_TYPE_PREFIX}-{SEQUENCE}');

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(blankCodeSheets()),
        ])
        ->assertRedirect();

    $organization = Organization::query()->firstOrFail();

    expect($organization->code)->toMatch('/^MA-\d+$/')
        ->and($organization->name_en)->toBe('Import Bureau');
});

// ── 2. Organization unit code ────────────────────────────────────────────────

it('generates the organization unit code from the code rule when the cell is blank', function (): void {
    $codes = previewCodes(blankCodeSheets(), structureImporterUser());

    $unit = $codes['Organization Units:2'];

    expect($unit['provided_code'])->toBeNull()
        ->and($unit['source'])->toBe('generated_by_code_rule')
        ->and($unit['generated_code'])->not->toBeNull();

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(blankCodeSheets()),
        ])
        ->assertRedirect();

    expect(OrganizationUnit::query()->firstOrFail()->code)->toBe($unit['generated_code']);
});

it('lets a child unit reference a parent whose own code was generated', function (): void {
    // The parent's code is generated, so the child can only point at it by the
    // code the importer *assigns*. Give the parent an explicit code and the
    // child a blank one, then assert the parent link survives.
    $sheets = blankCodeSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-ROOT', 'Head Office', null, 'IMPUNIT', null, null, null, 'active'],
            [null, 'Finance Department', null, 'IMPUNIT', 'U-ROOT', null, null, 'active'],
        ],
    ]);

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile($sheets),
        ])
        ->assertRedirect();

    $root = OrganizationUnit::query()->where('code', 'U-ROOT')->firstOrFail();
    $child = OrganizationUnit::query()->where('name_en', 'Finance Department')->firstOrFail();

    expect($child->code)->not->toBe('U-ROOT')
        ->and($child->parent_unit_id)->toBe($root->id);
});

// ── 3 & 4. Position code: owner/sequence and owner/host/sequence ─────────────

it('generates a direct position code as OWNER/SEQUENCE', function (): void {
    useCodeRuleFormat(CodeRuleEntityType::Position, '{OWNER_ORG_CODE}/{SEQUENCE}');

    $sheets = blankCodeSheets([
        StructureSheet::Organization->value => [
            structureHeaders(StructureSheet::Organization),
            ['MA-01', 'Import Bureau', null, 'IMPTYPE', null, 'active'],
        ],
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-ROOT', 'Head Office', null, 'IMPUNIT', null, null, null, 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            [null, null, 'Finance Officer', null, 'U-ROOT', null, 'active', null, '1'],
        ],
    ]);

    $codes = previewCodes($sheets, structureImporterUser());

    // The unit is a plain internal unit — no host segment.
    expect($codes['Positions:2']['generated_code'])->toMatch('#^MA-01/\d+$#');

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile($sheets)])
        ->assertRedirect();

    expect(Position::query()->firstOrFail()->job_position_code)->toMatch('#^MA-01/\d+$#');
});

it('generates a hosted-unit position code as OWNER/HOST/SEQUENCE', function (): void {
    useCodeRuleFormat(CodeRuleEntityType::Position, '{OWNER_ORG_CODE}/{HOST_ORG_CODE}/{SEQUENCE}');

    // A second organization that will host the unit.
    $type = OrganizationType::query()->where('code', 'IMPTYPE')->firstOrFail();
    Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'K-01',
        'name_en' => 'Host Organization',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $sheets = blankCodeSheets([
        StructureSheet::Organization->value => [
            structureHeaders(StructureSheet::Organization),
            ['MA-01', 'Owner Bureau', null, 'IMPTYPE', null, 'active'],
        ],
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            // The unit sits inside K-01 but its mandate is owned by MA-01.
            ['U-HOSTED', 'Hosted Office', null, 'IMPUNIT', null, 'K-01', 'MA-01', 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            [null, null, 'Field Officer', null, 'U-HOSTED', null, 'active', null, '1'],
        ],
    ]);

    $codes = previewCodes($sheets, structureImporterUser());

    expect($codes['Positions:2']['generated_code'])->toMatch('#^MA-01/K-01/\d+$#');

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile($sheets)])
        ->assertRedirect();

    expect(Position::query()->firstOrFail()->job_position_code)->toMatch('#^MA-01/K-01/\d+$#');
});

// ── 5. Employee number ───────────────────────────────────────────────────────

it('generates a global employee number that does not depend on the organization', function (): void {
    $sheets = blankCodeSheets([
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-001', null, 'Finance Officer', null, null, null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => [
            structureHeaders(StructureSheet::Employees),
            // employee_number blank → generated
            [null, 'Abebe', 'Kebede', null, null, null, null, 'P-001', '2026-01-01', 'active'],
        ],
    ]);

    $codes = previewCodes($sheets, structureImporterUser());

    expect($codes['Employees:2']['source'])->toBe('generated_by_code_rule')
        ->and($codes['Employees:2']['generated_code'])->toMatch('/^EMP-\d+$/');

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile($sheets)])
        ->assertRedirect();

    expect(Employee::query()->firstOrFail()->employee_number)->toMatch('/^EMP-\d+$/');
});

// ── 6. Provided codes are preserved ──────────────────────────────────────────

it('preserves and validates a provided code instead of generating one', function (): void {
    $codes = previewCodes(validStructureSheets(), structureImporterUser());

    expect($codes['Organization:2']['source'])->toBe('provided')
        ->and($codes['Organization:2']['provided_code'])->toBe('IMP-ORG')
        ->and($codes['Organization:2']['generated_code'])->toBeNull()
        ->and($codes['Organization Units:2']['provided_code'])->toBe('U-ROOT')
        ->and($codes['Positions:2']['provided_code'])->toBe('P-001');

    // Row 3 of Positions leaves the code blank — it is generated, proving the
    // two modes coexist inside one sheet.
    expect($codes['Positions:3']['source'])->toBe('generated_by_code_rule');
});

it('blocks a provided code that already exists', function (): void {
    $type = OrganizationType::query()->where('code', 'IMPTYPE')->firstOrFail();

    Position::query()->create([
        'organization_id' => Organization::query()->create([
            'organization_type_id' => $type->id,
            'code' => 'OTHER-ORG',
            'name_en' => 'Other',
            'status' => OrganizationStatus::Active,
            'effective_from' => now()->toDateString(),
        ])->id,
        'job_position_code' => 'P-001',
        'title_en' => 'Existing',
        'is_active' => true,
    ]);

    $messages = previewErrorMessages(validStructureSheets(), structureImporterUser());

    expect($messages)->toContain('Duplicate provided code "P-001".');
});

it('reports a row error when the code rule forbids hand-entered codes', function (): void {
    // Regression: the preview used to accept a provided code regardless of the
    // rule's allow_manual_override flag, and the import then blew up mid-write
    // with "Manual code override is not allowed for this rule." That must be a
    // row-level error in the preview instead.
    CodeRule::query()
        ->where('entity_type', CodeRuleEntityType::OrganizationUnit->value)
        ->update(['allow_manual_override' => false]);

    $sheets = blankCodeSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-HQ', 'Head Office', null, 'IMPUNIT', null, null, null, 'active'],
        ],
    ]);

    $messages = previewErrorMessages($sheets, structureImporterUser());

    expect($messages)->toContain(
        'The code rule for this record type does not allow codes to be entered by hand, so "U-HQ" cannot be used. Leave the cell empty to generate the code automatically.',
    );
});

it('blocks a hand-entered code when the rule needs override approval the user lacks', function (): void {
    // allow_manual_override is on, but the rule requires approval and the
    // importer has no code-rules.manageOverrides permission.
    CodeRule::query()
        ->where('entity_type', CodeRuleEntityType::OrganizationUnit->value)
        ->update(['allow_manual_override' => true, 'require_approval_for_override' => true]);

    $sheets = blankCodeSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-HQ', 'Head Office', null, 'IMPUNIT', null, null, null, 'active'],
        ],
    ]);

    $messages = previewErrorMessages($sheets, structureImporterUser());

    expect($messages)->toContain(
        'The code rule for this record type does not allow codes to be entered by hand, so "U-HQ" cannot be used. Leave the cell empty to generate the code automatically.',
    );
});

// ── Row references (#N) ──────────────────────────────────────────────────────

it('wires up a fully auto-generated file using #N row references', function (): void {
    // The real-world case: the Code Rules forbid hand-entered codes, so EVERY
    // code must be generated — and a generated code cannot be referenced by code
    // because it does not exist until import. `#N` names the row instead.
    CodeRule::query()->update(['allow_manual_override' => false]);

    $sheets = blankCodeSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            // row 2: Head Office (root)
            [null, 'Head Office', null, 'IMPUNIT', null, null, null, 'active'],
            // row 3: Finance Dept, child of row 2
            [null, 'Finance Department', null, 'IMPUNIT', '#2', null, null, 'active'],
            // row 4: Accounts Team, child of row 3
            [null, 'Accounts Team', null, 'IMPUNIT', '#3', null, null, 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            // row 2: a position inside the unit on Units row 4
            [null, null, 'Senior Accountant', null, '#4', null, 'active', null, '2'],
        ],
        StructureSheet::Employees->value => [
            structureHeaders(StructureSheet::Employees),
            // row 2: assigned to the position on Positions row 2
            [null, 'Abebe', 'Kebede', null, null, null, null, '#2', '2026-01-01', 'active'],
        ],
    ]);

    expect(previewErrorMessages($sheets, structureImporterUser()))->toBe([]);

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile($sheets)])
        ->assertRedirect();

    $head = OrganizationUnit::query()->where('name_en', 'Head Office')->firstOrFail();
    $finance = OrganizationUnit::query()->where('name_en', 'Finance Department')->firstOrFail();
    $accounts = OrganizationUnit::query()->where('name_en', 'Accounts Team')->firstOrFail();
    $position = Position::query()->firstOrFail();
    $employee = Employee::query()->firstOrFail();

    // Every code was generated…
    expect($head->code)->not->toBeEmpty()
        ->and($finance->code)->not->toBe($head->code);

    // …and the hierarchy the #N references described was built correctly.
    expect($finance->parent_unit_id)->toBe($head->id)
        ->and($accounts->parent_unit_id)->toBe($finance->id)
        ->and($position->organization_unit_id)->toBe($accounts->id)
        ->and($employee->currentAssignment->position_id)->toBe($position->id);
});

it('reports a row error for an #N reference that points nowhere', function (): void {
    $sheets = blankCodeSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            [null, 'Head Office', null, 'IMPUNIT', '#99', null, null, 'active'],
        ],
    ]);

    expect(previewErrorMessages($sheets, structureImporterUser()))
        ->toContain('Row reference "#99" does not point at a row of the referenced sheet.');
});

// ── 7. Missing code rule ─────────────────────────────────────────────────────

it('reports a row error when no code rule is configured', function (): void {
    CodeRule::query()->where('entity_type', CodeRuleEntityType::OrganizationUnit->value)->delete();

    $messages = previewErrorMessages(blankCodeSheets(), structureImporterUser());

    expect($messages)->toContain('Code rule is not configured for this record type.');
});

// ── 8. Duplicate generated code ──────────────────────────────────────────────

it('blocks a generated code that collides with a provided one', function (): void {
    // Force the unit rule to produce a fixed code, so the generated code on row 3
    // collides with the code provided on row 2.
    useCodeRuleFormat(CodeRuleEntityType::OrganizationUnit, '{PREFIX}', 'FIXED');

    $sheets = blankCodeSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['FIXED', 'Provided Unit', null, 'IMPUNIT', null, null, null, 'active'],
            [null, 'Generated Unit', null, 'IMPUNIT', null, null, null, 'active'],
        ],
    ]);

    $messages = previewErrorMessages($sheets, structureImporterUser());

    expect($messages)->toContain('Duplicate generated code "FIXED".');
});

// ── 9 & 10. Preview shows the code; confirm uses the same one ────────────────

it('imports under exactly the codes the preview displayed', function (): void {
    OrganizationType::query()->where('code', 'IMPTYPE')->update(['prefix' => 'MA']);
    useCodeRuleFormat(CodeRuleEntityType::Organization, '{ORG_TYPE_PREFIX}-{SEQUENCE}');

    $user = structureImporterUser();
    $sheets = blankCodeSheets();

    // What the user was shown…
    $previewed = previewCodes($sheets, $user);

    // …is what gets written.
    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile($sheets)])
        ->assertRedirect();

    expect(Organization::query()->firstOrFail()->code)->toBe($previewed['Organization:2']['generated_code'])
        ->and(OrganizationUnit::query()->firstOrFail()->code)->toBe($previewed['Organization Units:2']['generated_code'])
        ->and(Position::query()->firstOrFail()->job_position_code)->toBe($previewed['Positions:2']['generated_code']);
});

it('locks a random organization code between import preview and confirm', function (): void {
    useCodeRuleFormat(CodeRuleEntityType::Organization, 'ORG-{RAND_6}');

    $user = structureImporterUser();
    $sheets = blankCodeSheets();
    $previewedCode = previewCodes($sheets, $user)['Organization:2']['generated_code'];

    expect($previewedCode)->toMatch('/^ORG-\d{6}$/');

    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile($sheets),
        ])
        ->assertRedirect();

    expect(Organization::query()->firstOrFail()->code)->toBe($previewedCode);
});

it('advances the code rule sequence so a second import does not reuse the codes', function (): void {
    $user = structureImporterUser();

    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile(blankCodeSheets())])
        ->assertRedirect();

    $first = Organization::query()->firstOrFail()->code;

    // A second, otherwise identical file must not collide with the first.
    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile(blankCodeSheets())])
        ->assertRedirect();

    expect(Organization::query()->count())->toBe(2)
        ->and(Organization::query()->pluck('code')->unique())->toHaveCount(2)
        ->and(Organization::query()->pluck('code')->all())->toContain($first);
});

it('projects distinct codes for several blank rows in one sheet', function (): void {
    // Three blank unit codes must project to three *different* codes, not the
    // same "next number" three times.
    $sheets = blankCodeSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            [null, 'Unit One', null, 'IMPUNIT', null, null, null, 'active'],
            [null, 'Unit Two', null, 'IMPUNIT', null, null, null, 'active'],
            [null, 'Unit Three', null, 'IMPUNIT', null, null, null, 'active'],
        ],
    ]);

    $codes = previewCodes($sheets, structureImporterUser());

    $generated = collect(['Organization Units:2', 'Organization Units:3', 'Organization Units:4'])
        ->map(fn (string $key): string => $codes[$key]['generated_code'])
        ->all();

    expect($generated)->toHaveCount(3)
        ->and(array_unique($generated))->toHaveCount(3);
});

// ── 11. Auto-generation switched off ─────────────────────────────────────────

it('requires codes when auto-generation is turned off', function (): void {
    $props = previewProps(blankCodeSheets(), structureImporterUser(), [
        'auto_generate_codes' => false,
    ]);

    $messages = collect($props['errors'] ?? [])->flatten(1)->pluck('message')->all();

    expect($messages)->toContain('Code is required when auto-generation is turned off.');
});

// ── Employees without an assignment ──────────────────────────────────────────

it('imports an employee with no assignment when position and start date are both blank', function (): void {
    $sheets = blankCodeSheets([
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-001', null, 'Finance Officer', null, null, null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => [
            structureHeaders(StructureSheet::Employees),
            // No position_code, no assignment_start_date → person only.
            [null, 'Abebe', 'Kebede', null, null, null, null, null, null, 'active'],
        ],
    ]);

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile($sheets)])
        ->assertRedirect();

    $employee = Employee::query()->firstOrFail();

    expect($employee->first_name)->toBe('Abebe')
        ->and($employee->current_assignment_id)->toBeNull()
        ->and(DB::table('employee_assignments')->count())->toBe(0);
});
