<?php

declare(strict_types=1);

use App\Enums\InstitutionOfficeLevel;
use App\Enums\InstitutionOfficeStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\InstitutionOffice;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ── Helpers ──────────────────────────────────────────────────────────────────

function auditMakeOrg(): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'AUDIT-TEST-TYPE'],
        ['name_en' => 'Audit Test Type', 'is_demo' => false],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'AUDIT-ORG-'.uniqid(),
        'name_en' => 'Audit Test Organization',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function auditMakeOffice(Organization $institution, ?InstitutionOffice $parent = null): InstitutionOffice
{
    return InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'parent_office_id' => $parent?->id,
        'office_level' => InstitutionOfficeLevel::City->value,
        'office_code' => 'AUDIT-OFF-'.uniqid(),
        'name_en' => 'Audit Test Office',
        'status' => InstitutionOfficeStatus::Active->value,
        'is_head_office' => $parent === null,
    ]);
}

function auditMakeUnit(Organization $org, ?InstitutionOffice $office = null, ?OrganizationUnit $parent = null): OrganizationUnit
{
    $type = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'audit-unit-type'],
        ['name_en' => 'Audit Unit Type', 'is_active' => true, 'sort_order' => 0],
    );

    return OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'institution_office_id' => $office?->id,
        'parent_unit_id' => $parent?->id,
        'organization_unit_type_id' => $type->id,
        'unit_type' => 'unit',
        'code' => 'AUDIT-UNIT-'.uniqid(),
        'name_en' => 'Audit Test Unit',
        'status' => OrganizationUnitStatus::Active->value,
    ]);
}

function auditAdminUser(): User
{
    foreach ([
        'organizations.view',
        'organizations.manage',
        'organization-types.viewAny',
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
        'organization-units.update',
        'organization-units.archive',
        'organization-units.restore',
        'institution-offices.viewAny',
        'institution-offices.view',
        'institution-offices.create',
        'institution-offices.update',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    $role = Role::findOrCreate('AuditTestAdmin', 'web');
    $role->syncPermissions([
        'organizations.view',
        'organizations.manage',
        'organization-types.viewAny',
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
        'organization-units.update',
        'organization-units.archive',
        'organization-units.restore',
        'institution-offices.viewAny',
        'institution-offices.view',
        'institution-offices.create',
        'institution-offices.update',
    ]);

    $user = User::factory()->create();
    $user->assignRole('AuditTestAdmin');

    return $user;
}

// ── Test 1: Organization has organizationType() relation ─────────────────────

test('organization_has_organization_type_relation', function (): void {
    $org = auditMakeOrg();
    $org->load('organizationType');
    expect($org->organizationType)->not->toBeNull();
    expect($org->organizationType->id)->toBe($org->organization_type_id);
});

// ── Test 2: Organization type() and organizationType() return same relation ───

test('organization_type_alias_matches_type_relation', function (): void {
    $org = auditMakeOrg();
    expect($org->type()->getRelated())->toBeInstanceOf(OrganizationType::class);
    expect($org->organizationType()->getRelated())->toBeInstanceOf(OrganizationType::class);
    expect($org->organization_type_id)->toBe($org->type->id);
});

// ── Test 3: InstitutionOffice has institution() relation ──────────────────────

test('institution_office_has_institution_relation', function (): void {
    $org = auditMakeOrg();
    $office = auditMakeOffice($org);

    $office->load('institution');
    expect($office->institution)->not->toBeNull();
    expect($office->institution->id)->toBe($org->id);
});

// ── Test 4: InstitutionOffice childOffices returns children ───────────────────

test('institution_office_child_offices_returns_children', function (): void {
    $org = auditMakeOrg();
    $parent = auditMakeOffice($org);
    $child = auditMakeOffice($org, $parent);

    $parent->load('childOffices');
    $childIds = $parent->childOffices->pluck('id')->toArray();
    expect($childIds)->toContain($child->id);
});

// ── Test 5: InstitutionOffice parentOffice returns parent ─────────────────────

test('institution_office_parent_office_returns_parent', function (): void {
    $org = auditMakeOrg();
    $parent = auditMakeOffice($org);
    $child = auditMakeOffice($org, $parent);

    $child->load('parentOffice');
    expect($child->parentOffice)->not->toBeNull();
    expect($child->parentOffice->id)->toBe($parent->id);
});

// ── Test 6: Organization institutionOffices() returns offices ─────────────────

test('organization_institution_offices_returns_offices', function (): void {
    $org = auditMakeOrg();
    $office = auditMakeOffice($org);

    $org->load('institutionOffices');
    $officeIds = $org->institutionOffices->pluck('id')->toArray();
    expect($officeIds)->toContain($office->id);
});

// ── Test 7: Organization geographicInstitutionOffices() returns geo offices ───

test('organization_geographic_institution_offices_returns_offices', function (): void {
    $geoOrg = auditMakeOrg();
    $institution = auditMakeOrg();

    InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'geographic_organization_id' => $geoOrg->id,
        'office_level' => InstitutionOfficeLevel::SubCity->value,
        'office_code' => 'GEO-OFF-'.uniqid(),
        'name_en' => 'Geo Test Office',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);

    $geoOrg->load('geographicInstitutionOffices');
    expect($geoOrg->geographicInstitutionOffices)->toHaveCount(1);
});

// ── Test 8: OrganizationUnit has institutionOffice() relation ─────────────────

test('organization_unit_has_institution_office_relation', function (): void {
    $org = auditMakeOrg();
    $office = auditMakeOffice($org);
    $unit = auditMakeUnit($org, $office);

    $unit->load('institutionOffice');
    expect($unit->institutionOffice)->not->toBeNull();
    expect($unit->institutionOffice->id)->toBe($office->id);
});

// ── Test 9: OrganizationUnit children() returns child units ──────────────────

test('organization_unit_children_returns_child_units', function (): void {
    $org = auditMakeOrg();
    $parent = auditMakeUnit($org);
    $child = auditMakeUnit($org, null, $parent);

    $parent->load('children');
    $childIds = $parent->children->pluck('id')->toArray();
    expect($childIds)->toContain($child->id);
});

// ── Test 10: OrganizationUnit parent() returns parent unit ───────────────────

test('organization_unit_parent_returns_parent_unit', function (): void {
    $org = auditMakeOrg();
    $parent = auditMakeUnit($org);
    $child = auditMakeUnit($org, null, $parent);

    $child->load('parent');
    expect($child->parent)->not->toBeNull();
    expect($child->parent->id)->toBe($parent->id);
});

// ── Test 11: OrganizationUnit unitType() returns OrganizationUnitType ─────────

test('organization_unit_unit_type_returns_type', function (): void {
    $org = auditMakeOrg();
    $unit = auditMakeUnit($org);

    $unit->load('unitType');
    expect($unit->unitType)->not->toBeNull();
    expect($unit->unitType)->toBeInstanceOf(OrganizationUnitType::class);
});

// ── Test 12: Store validation — parent_unit_id must belong to same org ────────

test('store_organization_unit_rejects_parent_from_different_org', function (): void {
    $user = auditAdminUser();
    $org1 = auditMakeOrg();
    $org2 = auditMakeOrg();
    $parentUnit = auditMakeUnit($org1);

    $response = $this->actingAs($user)->post(route('organization-units.store'), [
        'organization_id' => $org2->id,
        'parent_unit_id' => $parentUnit->id,
        'name_en' => 'Invalid Unit',
        'status' => OrganizationUnitStatus::Active->value,
    ]);

    $response->assertSessionHasErrors(['parent_unit_id']);
});

// ── Test 13: Store institution office — parent must belong to same institution ─

test('store_institution_office_rejects_parent_from_different_institution', function (): void {
    $user = auditAdminUser();
    $inst1 = auditMakeOrg();
    $inst2 = auditMakeOrg();
    $parentUnit = auditMakeUnit($inst1);
    $unitType = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        ['name_en' => 'Office', 'is_active' => true, 'sort_order' => 0],
    );

    $response = $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $inst2->id,
        'organization_unit_type_id' => $unitType->id,
        'parent_unit_id' => $parentUnit->id,
        'code' => 'CROSS-OFF-'.uniqid(),
        'name_en' => 'Cross Institution Office',
        'status' => OrganizationUnitStatus::Active->value,
    ]);

    $response->assertSessionHasErrors(['parent_unit_id']);
});

// ── Test 14: Store organization requires organization_type_id ─────────────────

test('store_organization_requires_organization_type_id', function (): void {
    $user = auditAdminUser();

    $response = $this->actingAs($user)->post(route('organizations.store'), [
        'name_en' => 'Missing Type Org',
        'status' => OrganizationStatus::Active->value,
    ]);

    $response->assertSessionHasErrors(['organization_type_id']);
});

// ── Test 15: InstitutionOffice organizationUnits() returns units ──────────────

test('institution_office_organization_units_returns_units', function (): void {
    $org = auditMakeOrg();
    $office = auditMakeOffice($org);
    $unit = auditMakeUnit($org, $office);

    $office->load('organizationUnits');
    $unitIds = $office->organizationUnits->pluck('id')->toArray();
    expect($unitIds)->toContain($unit->id);
});

// ── Test 16: Organization unit without institution_office still loads correctly ─

test('organization_unit_without_institution_office_loads_correctly', function (): void {
    $org = auditMakeOrg();
    $unit = auditMakeUnit($org, null);

    $unit->load('institutionOffice', 'organization');
    expect($unit->institutionOffice)->toBeNull();
    expect($unit->organization)->not->toBeNull();
    expect($unit->organization->id)->toBe($org->id);
});
