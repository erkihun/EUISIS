<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\AuditLog;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\OrganizationStructureImport\StructureSheet;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/StructureImportHelpers.php';

beforeEach(function (): void {
    // Assertions below compare against the English messages; pin the locale so
    // the app's configured default (Amharic) does not decide the test outcome.
    app()->setLocale('en');

    seedStructureReferenceData();
});

// ── 1. Authorization ─────────────────────────────────────────────────────────

it('lets an authorized user open the import page', function (): void {
    $this->actingAs(structureImporterUser())
        ->get(route('organizations.import-structure.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Organizations/ImportStructure'));
});

it('forbids a user without the import permission from opening the import page', function (): void {
    $this->actingAs(structureNonImporterUser())
        ->get(route('organizations.import-structure.create'))
        ->assertForbidden();
});

it('forbids a user without the import permission from previewing or confirming', function (): void {
    $user = structureNonImporterUser();

    $this->actingAs($user)
        ->post(route('organizations.import-structure.preview'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertForbidden();

    expect(Organization::query()->where('code', 'IMP-ORG')->exists())->toBeFalse();
});

// ── 2. Preview ───────────────────────────────────────────────────────────────

it('previews a valid workbook without writing anything', function (): void {
    $preview = previewProps(validStructureSheets(), structureImporterUser());

    expect($preview['can_import'])->toBeTrue()
        ->and($preview['mode'])->toBe('create')
        ->and($preview['error_count'])->toBe(0)
        ->and($preview['counts']['units'])->toBe(2)
        ->and($preview['counts']['positions'])->toBe(2)
        ->and($preview['counts']['employees'])->toBe(1)
        ->and($preview['organization']['code'])->toBe('IMP-ORG');

    // Preview must be side-effect free apart from the audit trail.
    expect(Organization::query()->where('code', 'IMP-ORG')->exists())->toBeFalse()
        ->and(OrganizationUnit::query()->count())->toBe(0)
        ->and(Position::query()->count())->toBe(0);
});

it('keeps the wizard on a refreshable GET url after previewing', function (): void {
    // Regression: preview used to render Inertia in place, which left the POST-only
    // /preview URL in the address bar — refreshing it issued a GET and 405'd.
    // Preview must redirect back to the wizard, whose URL survives a reload.
    $user = structureImporterUser();

    $this->actingAs($user)
        ->post(route('organizations.import-structure.preview'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertRedirect(route('organizations.import-structure.create'));

    // Reloading that URL still works, and still shows the preview.
    $this->actingAs($user)
        ->get(route('organizations.import-structure.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('preview.can_import', true));
});

it('nests the unit tree in the preview', function (): void {
    $preview = previewProps(validStructureSheets(), structureImporterUser());

    expect($preview['unit_tree'][0]['code'])->toBe('U-ROOT')
        ->and($preview['unit_tree'][0]['children'][0]['code'])->toBe('U-CHILD');
});

// ── 3. Structural validation ─────────────────────────────────────────────────

it('reports an error when a required sheet is missing', function (): void {
    $sheets = validStructureSheets([StructureSheet::Positions->value => null]);

    $preview = previewProps($sheets, structureImporterUser());

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['errors']['Positions'][0]['message'])->toBe('Required sheet missing: Positions');
});

it('reports an error when a required column is missing', function (): void {
    // Drop unit_name_en (index 1) from the Organization Units header and rows.
    $headers = structureHeaders(StructureSheet::OrganizationUnits);
    unset($headers[1]);

    $sheets = validStructureSheets([
        StructureSheet::OrganizationUnits->value => [
            array_values($headers),
            ['U-ROOT', null, 'IMPUNIT', null, null, null, 'active'],
        ],
    ]);

    $preview = previewProps($sheets, structureImporterUser());

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['errors']['Organization Units'][0]['message'])
        ->toBe('Required column missing: unit_name_en (sheet Organization Units)');
});

it('rejects a file that is not a spreadsheet', function (): void {
    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.preview'), [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])
        ->assertSessionHasErrors('file');
});

// ── 4. Row-level validation ──────────────────────────────────────────────────

it('reports a row error for a duplicate unit code', function (): void {
    $sheets = validStructureSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-DUP', 'First', null, 'IMPUNIT', null, null, null, 'active'],
            ['U-DUP', 'Second', null, 'IMPUNIT', null, null, null, 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-001', null, 'Officer', null, 'U-DUP', null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => null,
    ]);

    $preview = previewProps($sheets, structureImporterUser());

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['errors']['Organization Units'][0]['row'])->toBe(3)
        ->and($preview['errors']['Organization Units'][0]['message'])
        ->toBe('Duplicate unit code "U-DUP" (already used on row 2).');
});

it('reports a row error for an unknown parent unit', function (): void {
    $sheets = validStructureSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-ROOT', 'Head Office', null, 'IMPUNIT', 'U-GHOST', null, null, 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-001', null, 'Officer', null, 'U-ROOT', null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => null,
    ]);

    $preview = previewProps($sheets, structureImporterUser());

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['errors']['Organization Units'][0]['column'])->toBe('parent_unit_code')
        ->and($preview['errors']['Organization Units'][0]['message'])
        ->toBe('Parent unit "U-GHOST" was not found in this file or in the database.');
});

it('reports an error for a circular parent unit relationship', function (): void {
    $sheets = validStructureSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-A', 'Unit A', null, 'IMPUNIT', 'U-B', null, null, 'active'],
            ['U-B', 'Unit B', null, 'IMPUNIT', 'U-A', null, null, 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-001', null, 'Officer', null, 'U-A', null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => null,
    ]);

    $messages = previewErrorMessages($sheets, structureImporterUser());

    expect($messages)->toContain('Unit "u-a" is part of a circular parent relationship.');
});

it('reports a row error for a duplicate position code', function (): void {
    $sheets = validStructureSheets([
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-DUP', null, 'Officer One', null, 'U-ROOT', null, 'active', null, '1'],
            ['P-DUP', null, 'Officer Two', null, 'U-ROOT', null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => null,
    ]);

    $preview = previewProps($sheets, structureImporterUser());

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['errors']['Positions'][0]['message'])
        ->toBe('Duplicate position code "P-DUP" (already used on row 2).');
});

it('reports errors for unknown reference codes', function (): void {
    $sheets = validStructureSheets([
        StructureSheet::Organization->value => [
            structureHeaders(StructureSheet::Organization),
            ['IMP-ORG', 'Import Bureau', null, 'NOPE', null, 'active'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-001', null, 'Officer', null, 'U-ROOT', 'Grade Z', 'active', 'NOOCC', '0'],
        ],
        StructureSheet::Employees->value => null,
    ]);

    $messages = previewErrorMessages($sheets, structureImporterUser());

    expect($messages)
        ->toContain('Organization type "NOPE" does not exist.')
        ->toContain('Job grade "Grade Z" does not exist.')
        ->toContain('Profession "NOOCC" does not exist.')
        ->toContain('Slots must be a whole number of at least 1 (got "0").');
});

it('blocks assigning an employee to a position with no vacant slot', function (): void {
    // One slot, two employees claiming it.
    $sheets = validStructureSheets([
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-001', null, 'Finance Officer', null, 'U-CHILD', null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => [
            structureHeaders(StructureSheet::Employees),
            [null, 'Abebe', 'Kebede', null, null, null, null, 'P-001', '2026-01-01', 'active'],
            [null, 'Almaz', 'Tadesse', null, null, null, null, 'P-001', '2026-01-01', 'active'],
        ],
    ]);

    $messages = previewErrorMessages($sheets, structureImporterUser());

    expect($messages)->toContain('Position "P-001" has no vacant slot (1 slot(s) available).');
});

it('blocks positions under an inactive unit', function (): void {
    $sheets = validStructureSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-OFF', 'Closed Department', null, 'IMPUNIT', null, null, null, 'inactive'],
        ],
        StructureSheet::Positions->value => [
            structureHeaders(StructureSheet::Positions),
            ['P-001', null, 'Officer', null, 'U-OFF', null, 'active', null, '1'],
        ],
        StructureSheet::Employees->value => null,
    ]);

    $messages = previewErrorMessages($sheets, structureImporterUser());

    expect($messages)->toContain('Unit "U-OFF" is not active and cannot receive new positions.');
});

// ── 5. Import ────────────────────────────────────────────────────────────────

it('imports the full structure on confirm', function (): void {
    $user = structureImporterUser();

    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertRedirect();

    $organization = Organization::query()->where('code', 'IMP-ORG')->firstOrFail();

    expect($organization->name_en)->toBe('Import Bureau')
        ->and($organization->name_am)->toBe('የማስመጣት ቢሮ')
        ->and($organization->status)->toBe(OrganizationStatus::Active);

    $units = OrganizationUnit::query()->where('organization_id', $organization->id)->get()->keyBy('code');

    expect($units)->toHaveCount(2)
        ->and($units['U-CHILD']->parent_unit_id)->toBe($units['U-ROOT']->id)
        ->and($units['U-ROOT']->parent_unit_id)->toBeNull()
        ->and($units['U-ROOT']->status)->toBe(OrganizationUnitStatus::Active);
});

it('places positions under the unit named in the sheet', function (): void {
    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertRedirect();

    $organization = Organization::query()->where('code', 'IMP-ORG')->firstOrFail();
    $child = OrganizationUnit::query()->where('organization_id', $organization->id)->where('code', 'U-CHILD')->firstOrFail();
    $root = OrganizationUnit::query()->where('organization_id', $organization->id)->where('code', 'U-ROOT')->firstOrFail();

    $financeOfficer = Position::query()->where('job_position_code', 'P-001')->firstOrFail();
    $auditor = Position::query()->where('title_en', 'Auditor')->firstOrFail();

    expect($financeOfficer->organization_unit_id)->toBe($child->id)
        ->and($financeOfficer->organization_id)->toBe($organization->id)
        ->and($financeOfficer->grade_level)->toBe('Grade A')
        ->and($auditor->organization_unit_id)->toBe($root->id);
});

it('generates a missing position code using the code rules', function (): void {
    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertRedirect();

    $auditor = Position::query()->where('title_en', 'Auditor')->firstOrFail();

    // The Position code rule is {PREFIX}-{SEQUENCE} with prefix POS.
    expect($auditor->job_position_code)->toMatch('/^POS-\d{4}$/');

    // The code that WAS supplied is kept verbatim.
    expect(Position::query()->where('job_position_code', 'P-001')->exists())->toBeTrue();
});

it('creates employees and active assignments only on vacant positions', function (): void {
    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertRedirect();

    $employee = Employee::query()->where('first_name', 'Abebe')->firstOrFail();
    $position = Position::query()->where('job_position_code', 'P-001')->firstOrFail();

    $assignment = EmployeeAssignment::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($employee->status)->toBe(EmployeeStatus::Active)
        ->and($employee->employee_number)->toMatch('/^EMP-\d{4}$/')
        ->and($employee->current_assignment_id)->toBe($assignment->id)
        ->and($assignment->position_id)->toBe($position->id)
        ->and($assignment->organization_unit_id)->toBe($position->organization_unit_id)
        ->and($assignment->is_current)->toBeTrue()
        ->and($assignment->effective_from->toDateString())->toBe('2026-01-01');
});

it('skips employees when the wizard opts out', function (): void {
    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
            'import_employees' => false,
        ])
        ->assertRedirect();

    expect(Employee::query()->count())->toBe(0)
        ->and(EmployeeAssignment::query()->count())->toBe(0)
        ->and(Position::query()->count())->toBe(2);
});

it('updates an existing organization instead of duplicating it', function (): void {
    $type = OrganizationType::query()->where('code', 'IMPTYPE')->firstOrFail();

    $existing = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'IMP-ORG',
        'name_en' => 'Old Name',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertRedirect();

    expect(Organization::query()->where('code', 'IMP-ORG')->count())->toBe(1)
        ->and($existing->fresh()->name_en)->toBe('Import Bureau');
});

// ── 6. Audit + rollback ──────────────────────────────────────────────────────

it('writes an audit log for the import', function (): void {
    $user = structureImporterUser();

    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets(), 'my-structure.xlsx'),
        ])
        ->assertRedirect();

    $log = AuditLog::query()
        ->where('event_type', AuditEventType::OrganizationStructureImported->value)
        ->latest('created_at')
        ->firstOrFail();

    expect($log->actor_user_id)->toBe($user->getKey())
        ->and($log->new_values['file_name'])->toBe('my-structure.xlsx')
        ->and($log->new_values['uploaded_by'])->toBe($user->getKey())
        ->and($log->new_values['units_created'])->toBe(2)
        ->and($log->new_values['positions_created'])->toBe(2)
        ->and($log->new_values['employees_created'])->toBe(1)
        ->and($log->new_values['assignments_created'])->toBe(1)
        ->and($log->new_values['error_count'])->toBe(0)
        ->and($log->new_values['imported_at'])->not->toBeNull();
});

it('rolls the whole import back when a write fails', function (): void {
    $user = structureImporterUser();

    // Make the write blow up *after* the organization and units are already
    // inserted: the Employee code rule is switched to a format whose token
    // cannot resolve without employee context, so generating the employee number
    // throws — leaving a half-written structure the transaction must undo.
    CodeRule::query()
        ->where('entity_type', CodeRuleEntityType::Employee->value)
        ->update(['format' => '{SERVICE_TYPE_CODE}-{SEQUENCE}']);

    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ])
        ->assertSessionHasErrors('file');

    // Nothing at all may survive the failed transaction.
    expect(Organization::query()->where('code', 'IMP-ORG')->exists())->toBeFalse()
        ->and(OrganizationUnit::query()->count())->toBe(0)
        ->and(Position::query()->count())->toBe(0)
        ->and(Employee::query()->count())->toBe(0);

    expect(AuditLog::query()->where('event_type', AuditEventType::OrganizationStructureImportFailed->value)->exists())
        ->toBeTrue();
});

it('refuses to import a workbook that still has errors', function (): void {
    $sheets = validStructureSheets([
        StructureSheet::OrganizationUnits->value => [
            structureHeaders(StructureSheet::OrganizationUnits),
            ['U-DUP', 'First', null, 'IMPUNIT', null, null, null, 'active'],
            ['U-DUP', 'Second', null, 'IMPUNIT', null, null, null, 'active'],
        ],
        StructureSheet::Employees->value => null,
    ]);

    $user = structureImporterUser();

    // A confirm that fails re-validation goes back to the wizard carrying the
    // errors — it must not land on the organization page.
    $this->actingAs($user)
        ->post(route('organizations.import-structure.confirm'), ['file' => structureWorkbookFile($sheets)])
        ->assertRedirect(route('organizations.import-structure.create'));

    $this->actingAs($user)
        ->get(route('organizations.import-structure.create'))
        ->assertInertia(fn ($page) => $page
            ->component('Organizations/ImportStructure')
            ->where('preview.can_import', false),
        );

    expect(Organization::query()->where('code', 'IMP-ORG')->exists())->toBeFalse()
        ->and(OrganizationUnit::query()->count())->toBe(0);
});

// ── 7. Template ──────────────────────────────────────────────────────────────

it('serves the blank structure template', function (): void {
    $this->actingAs(structureImporterUser())
        ->get(route('organizations.import-structure.template'))
        ->assertOk()
        ->assertDownload('organization-structure-template.xlsx');
});
