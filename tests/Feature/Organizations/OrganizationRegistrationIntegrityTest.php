<?php

declare(strict_types=1);

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationNameHistory;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\OrganizationStructureImport\StructureSheet;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../OrganizationStructure/StructureImportHelpers.php';

/*
 * Registration rules that keep the institution register clean before other
 * records start pointing at it: one live organization per name, statuses a
 * new or edited record may take, a type change that still fits the hierarchy,
 * a name history that reads as a sequence, and the same rules for the import.
 */

beforeEach(function (): void {
    app()->setLocale('en');

    foreach (['organizations.view', 'organizations.create', 'organizations.update'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(['organizations.view', 'organizations.create', 'organizations.update']);
});

function registrationAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function registrationType(string $code = 'BUREAU', ?array $parentTypes = null): OrganizationType
{
    return OrganizationType::query()->create([
        'code' => $code,
        'name_en' => "Type {$code}",
        'parent_allowed_types' => $parentTypes,
    ]);
}

function registrationOrganization(OrganizationType $type, string $code, string $nameEn, string $nameAm, OrganizationStatus $status = OrganizationStatus::Active): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => $nameEn,
        'name_am' => $nameAm,
        'status' => $status,
    ]);
}

/** @return array<string, mixed> */
function registrationPayload(OrganizationType $type, array $overrides = []): array
{
    return $overrides + [
        'organization_type_id' => $type->id,
        'code' => 'ORG-NEW',
        'name_en' => 'Bureau of Finance',
        'name_am' => 'የገንዘብ ቢሮ',
        'status' => 'active',
    ];
}

// ── One live organization per name ──────────────────────────────────────────

it('rejects an English name another live organization holds, ignoring case and extra spaces', function (): void {
    $type = registrationType();
    registrationOrganization($type, 'ORG-1', 'Bureau of Finance', 'የገንዘብ ቢሮ');

    $this->actingAs(registrationAdmin())
        ->post(route('organizations.store'), registrationPayload($type, [
            'name_en' => '  bureau   OF finance ',
            'name_am' => 'ሌላ ስም',
        ]))
        ->assertSessionHasErrors('name_en');

    expect(Organization::query()->count())->toBe(1);
});

it('rejects an Amharic name another live organization holds', function (): void {
    $type = registrationType();
    registrationOrganization($type, 'ORG-1', 'Bureau of Finance', 'የገንዘብ ቢሮ');

    $this->actingAs(registrationAdmin())
        ->post(route('organizations.store'), registrationPayload($type, [
            'name_en' => 'Finance Office',
            'name_am' => 'የገንዘብ  ቢሮ',
        ]))
        ->assertSessionHasErrors('name_am')
        ->assertSessionDoesntHaveErrors('name_en');
});

it('lets a re-established body reuse the name of an archived organization', function (): void {
    $type = registrationType();
    registrationOrganization($type, 'ORG-1', 'Bureau of Finance', 'የገንዘብ ቢሮ', OrganizationStatus::Archived);

    $this->actingAs(registrationAdmin())
        ->post(route('organizations.store'), registrationPayload($type))
        ->assertSessionHasNoErrors();

    expect(Organization::query()->where('code', 'ORG-NEW')->value('name_en'))->toBe('Bureau of Finance');
});

it('stores names with runs of spaces collapsed', function (): void {
    $type = registrationType();

    $this->actingAs(registrationAdmin())
        ->post(route('organizations.store'), registrationPayload($type, ['name_en' => ' Bureau   of  Health ']))
        ->assertSessionHasNoErrors();

    expect(Organization::query()->where('code', 'ORG-NEW')->value('name_en'))->toBe('Bureau of Health');
});

it('lets an edit keep its own name but not take another organization\'s', function (): void {
    $type = registrationType();
    $finance = registrationOrganization($type, 'ORG-1', 'Bureau of Finance', 'የገንዘብ ቢሮ');
    registrationOrganization($type, 'ORG-2', 'Bureau of Health', 'የጤና ቢሮ');
    $base = ['organization_type_id' => $type->id, 'code' => 'ORG-1', 'status' => 'active'];

    $this->actingAs(registrationAdmin())
        ->patch(route('organizations.update', $finance), $base + ['name_en' => 'Bureau of Finance', 'name_am' => 'የገንዘብ ቢሮ'])
        ->assertSessionHasNoErrors();

    $this->actingAs(registrationAdmin())
        ->patch(route('organizations.update', $finance), $base + ['name_en' => 'BUREAU OF HEALTH', 'name_am' => 'የገንዘብ ቢሮ'])
        ->assertSessionHasErrors('name_en');
});

// ── Statuses ────────────────────────────────────────────────────────────────

it('does not register a new organization as archived, merged or dissolved', function (string $status): void {
    $type = registrationType();

    $this->actingAs(registrationAdmin())
        ->post(route('organizations.store'), registrationPayload($type, ['status' => $status]))
        ->assertSessionHasErrors('status');

    expect(Organization::query()->count())->toBe(0);
})->with(['archived', 'merged', 'dissolved']);

it('does not mark an organization merged or archived from the edit form', function (string $status): void {
    $type = registrationType();
    $organization = registrationOrganization($type, 'ORG-1', 'Bureau of Finance', 'የገንዘብ ቢሮ');

    $this->actingAs(registrationAdmin())
        ->patch(route('organizations.update', $organization), [
            'organization_type_id' => $type->id,
            'code' => 'ORG-1',
            'name_en' => 'Bureau of Finance',
            'name_am' => 'የገንዘብ ቢሮ',
            'status' => $status,
        ])
        ->assertSessionHasErrors('status');

    expect($organization->fresh()->status)->toBe(OrganizationStatus::Active);
})->with(['archived', 'merged']);

it('keeps saving an organization that is already archived', function (): void {
    $type = registrationType();
    $organization = registrationOrganization($type, 'ORG-1', 'Bureau of Finance', 'የገንዘብ ቢሮ', OrganizationStatus::Archived);

    $this->actingAs(registrationAdmin())
        ->patch(route('organizations.update', $organization), [
            'organization_type_id' => $type->id,
            'code' => 'ORG-1',
            'name_en' => 'Bureau of Finance',
            'name_am' => 'የገንዘብ ቢሮ',
            'legal_basis_ref' => 'Proclamation 94/2019',
            'status' => 'archived',
        ])
        ->assertSessionHasNoErrors();

    expect($organization->fresh()->legal_basis_ref)->toBe('Proclamation 94/2019');
});

// ── Type change against the hierarchy ───────────────────────────────────────

it('refuses a type change that no longer fits a parent link in the hierarchy', function (): void {
    $cityType = registrationType('CITY');
    $bureauType = registrationType('BUREAU', ['CITY']);
    $woredaType = registrationType('WOREDA', ['SUBCITY']);
    $city = registrationOrganization($cityType, 'CITY-1', 'City Administration', 'የከተማ አስተዳደር');
    $bureau = registrationOrganization($bureauType, 'BUR-1', 'Bureau of Finance', 'የገንዘብ ቢሮ');

    $version = HierarchyVersion::query()->create([
        'version_name' => 'Structure 2026',
        'status' => HierarchyVersionStatus::Published,
        'effective_from' => '2026-01-01',
    ]);
    OrganizationEdge::query()->create([
        'hierarchy_version_id' => $version->id,
        'parent_organization_id' => $city->id,
        'child_organization_id' => $bureau->id,
        'relationship_type' => 'reports_to',
    ]);

    $payload = ['code' => 'BUR-1', 'name_en' => 'Bureau of Finance', 'name_am' => 'የገንዘብ ቢሮ', 'status' => 'active'];

    $this->actingAs(registrationAdmin())
        ->patch(route('organizations.update', $bureau), $payload + ['organization_type_id' => $woredaType->id])
        ->assertSessionHasErrors('organization_type_id');

    expect($bureau->fresh()->organization_type_id)->toBe($bureauType->id);

    // A type whose rules still accept the city parent is fine.
    $agencyType = registrationType('AGENCY', ['CITY']);

    $this->actingAs(registrationAdmin())
        ->patch(route('organizations.update', $bureau), $payload + ['organization_type_id' => $agencyType->id])
        ->assertSessionHasNoErrors();
});

it('refuses a type change that a child organization does not accept as its parent', function (): void {
    $cityType = registrationType('CITY');
    $otherType = registrationType('OTHER');
    $bureauType = registrationType('BUREAU', ['CITY']);
    $city = registrationOrganization($cityType, 'CITY-1', 'City Administration', 'የከተማ አስተዳደር');
    $bureau = registrationOrganization($bureauType, 'BUR-1', 'Bureau of Finance', 'የገንዘብ ቢሮ');

    $draft = HierarchyVersion::query()->create([
        'version_name' => 'Draft 2026',
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => '2026-01-01',
    ]);
    OrganizationEdge::query()->create([
        'hierarchy_version_id' => $draft->id,
        'parent_organization_id' => $city->id,
        'child_organization_id' => $bureau->id,
        'relationship_type' => 'reports_to',
    ]);

    $this->actingAs(registrationAdmin())
        ->patch(route('organizations.update', $city), [
            'organization_type_id' => $otherType->id,
            'code' => 'CITY-1',
            'name_en' => 'City Administration',
            'name_am' => 'የከተማ አስተዳደር',
            'status' => 'active',
        ])
        ->assertSessionHasErrors('organization_type_id');
});

// ── Name history ────────────────────────────────────────────────────────────

it('closes the previous name and starts the new one on the day of a rename', function (): void {
    Carbon::setTestNow('2026-11-01 10:00:00');
    $type = registrationType();

    $this->actingAs(registrationAdmin())
        ->post(route('organizations.store'), registrationPayload($type, ['effective_from' => '2020-07-08']))
        ->assertSessionHasNoErrors();
    $organization = Organization::query()->where('code', 'ORG-NEW')->firstOrFail();

    $this->actingAs(registrationAdmin())
        ->patch(route('organizations.update', $organization), [
            'organization_type_id' => $type->id,
            'code' => 'ORG-NEW',
            'name_en' => 'Bureau of Finance and Economic Development',
            'name_am' => 'የገንዘብና ኢኮኖሚ ልማት ቢሮ',
            'status' => 'active',
            'effective_from' => '2020-07-08',
        ])
        ->assertSessionHasNoErrors();

    $histories = OrganizationNameHistory::query()
        ->where('organization_id', $organization->id)
        ->orderBy('effective_from')
        ->get();

    expect($histories)->toHaveCount(2)
        ->and($histories[0]->name_en)->toBe('Bureau of Finance')
        ->and($histories[0]->effective_from->toDateString())->toBe('2020-07-08')
        ->and($histories[0]->effective_to->toDateString())->toBe('2026-11-01')
        ->and($histories[1]->name_en)->toBe('Bureau of Finance and Economic Development')
        ->and($histories[1]->effective_from->toDateString())->toBe('2026-11-01')
        ->and($histories[1]->effective_to)->toBeNull();

    Carbon::setTestNow();
});

// ── Child registration message ──────────────────────────────────────────────

it('tells the registrar the child appears once the draft version is published', function (): void {
    $type = registrationType();
    $parent = registrationOrganization($type, 'CITY-1', 'City Administration', 'የከተማ አስተዳደር');
    $draft = HierarchyVersion::query()->create([
        'version_name' => 'Structure 2027',
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => '2027-01-01',
    ]);

    $this->actingAs(registrationAdmin())
        ->post(route('organizations.store'), registrationPayload($type, [
            'parent_organization_id' => $parent->id,
            'hierarchy_version_id' => $draft->id,
            'relationship_type' => 'reports_to',
        ]))
        ->assertRedirect(route('organizations.show', $parent))
        ->assertSessionHas('flash.message', __('organizations.child_organization_pending_publish', ['version' => 'Structure 2027']));
});

// ── Structure import ────────────────────────────────────────────────────────

it('refuses an import that registers a new organization under a name already taken', function (): void {
    seedStructureReferenceData();
    $type = OrganizationType::query()->where('code', 'IMPTYPE')->firstOrFail();
    registrationOrganization($type, 'ORG-OLD', 'Import Bureau', 'ሌላ ቢሮ');

    $messages = previewErrorMessages(validStructureSheets(), structureImporterUser());

    expect($messages)->toContain(__('organization-structure-import.errors.organization_name_taken', [
        'name' => 'Import Bureau',
        'code' => 'ORG-OLD',
    ]));
});

it('requires the Amharic name for an organization the import registers', function (): void {
    seedStructureReferenceData();

    $sheets = validStructureSheets([
        StructureSheet::Organization->value => [
            structureHeaders(StructureSheet::Organization),
            ['IMP-ORG', 'Import Bureau', null, 'IMPTYPE', null, 'active'],
        ],
    ]);

    expect(previewErrorMessages($sheets, structureImporterUser()))
        ->toContain(__('organization-structure-import.errors.organization_name_am_required'));
});

it('warns that the import does not link a new organization to its parent', function (): void {
    seedStructureReferenceData();
    $type = OrganizationType::query()->where('code', 'IMPTYPE')->firstOrFail();
    registrationOrganization($type, 'CITY-1', 'City Administration', 'የከተማ አስተዳደር');

    $sheets = validStructureSheets([
        StructureSheet::Organization->value => [
            structureHeaders(StructureSheet::Organization),
            ['IMP-ORG', 'Import Bureau', 'የማስመጣት ቢሮ', 'IMPTYPE', 'CITY-1', 'active'],
        ],
    ]);

    $warnings = collect(previewProps($sheets, structureImporterUser())['warnings'] ?? [])
        ->flatten(1)
        ->pluck('message')
        ->all();

    expect($warnings)->toContain(__('organization-structure-import.warnings.parent_not_linked', ['code' => 'CITY-1']));
});

it('keeps a name history row for an organization the import registers', function (): void {
    seedStructureReferenceData();

    $this->actingAs(structureImporterUser())
        ->post(route('organizations.import-structure.confirm'), [
            'file' => structureWorkbookFile(validStructureSheets()),
        ]);

    $organization = Organization::query()->where('code', 'IMP-ORG')->firstOrFail();

    expect(OrganizationNameHistory::query()->where('organization_id', $organization->id)->pluck('name_en')->all())
        ->toBe(['Import Bureau']);
});
