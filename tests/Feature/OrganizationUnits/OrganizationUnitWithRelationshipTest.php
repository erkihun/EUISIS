<?php

declare(strict_types=1);

use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use App\Models\OrganizationUnitType;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function ouRelTestAdmin(): User
{
    foreach ([
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    $role = Role::findOrCreate('OURelTestAdmin', 'web');
    $role->syncPermissions([
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function ouRelTestOrganization(string $suffix = ''): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'OU-REL-TEST-TYPE'],
        ['name_en' => 'OU Rel Test Type'],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OUREL-' . uniqid() . $suffix,
        'name_en' => 'OU Relationship Test Org ' . $suffix,
        'status' => OrganizationStatus::Active,
    ]);
}

// ── Creating an org unit with functional reporting creates a relationship ──

test('org unit with functional reporting creates organization_unit_relationships record', function (): void {
    $user = ouRelTestAdmin();
    $structuralOrg = ouRelTestOrganization('structural');
    $reportingOrg = ouRelTestOrganization('reporting');

    $unitType = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        ['prefix' => 'OFF', 'name_en' => 'Office', 'sort_order' => 1, 'is_active' => true],
    );

    $code = 'OUREL-' . uniqid();

    $this->actingAs($user)
        ->post(route('institution-offices.store'), [
            'organization_id' => $structuralOrg->id,
            'organization_unit_type_id' => $unitType->id,
            'code' => $code,
            'name_en' => 'Relationship Test Office',
            'functional_reporting_organization_id' => $reportingOrg->id,
            'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
            'status' => 'active',
        ])
        ->assertRedirect();

    $unit = OrganizationUnit::query()->where('code', $code)->firstOrFail();

    $this->assertDatabaseHas('organization_unit_relationships', [
        'source_unit_id' => $unit->id,
        'target_type' => RelationshipTargetType::Organization->value,
        'target_id' => $reportingOrg->id,
        'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
        'status' => RelationshipStatus::Active->value,
    ]);
});

// ── Functional reporting does NOT change the unit's organization_id ────────

test('functional reporting relationship does not change the structural organization_id', function (): void {
    $user = ouRelTestAdmin();
    $structuralOrg = ouRelTestOrganization('structural2');
    $reportingOrg = ouRelTestOrganization('reporting2');

    $unitType = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        ['prefix' => 'OFF', 'name_en' => 'Office', 'sort_order' => 1, 'is_active' => true],
    );

    $code = 'OUREL-OID-' . uniqid();

    $this->actingAs($user)
        ->post(route('institution-offices.store'), [
            'organization_id' => $structuralOrg->id,
            'organization_unit_type_id' => $unitType->id,
            'code' => $code,
            'name_en' => 'Structural Integrity Test Office',
            'functional_reporting_organization_id' => $reportingOrg->id,
            'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
            'status' => 'active',
        ])
        ->assertRedirect();

    // The unit belongs to the structural org, not the reporting org
    $this->assertDatabaseHas('organization_units', [
        'code' => $code,
        'organization_id' => $structuralOrg->id,
    ]);

    $this->assertDatabaseMissing('organization_units', [
        'code' => $code,
        'organization_id' => $reportingOrg->id,
    ]);
});

// ── No relationship created when functional_reporting_organization_id is absent ──

test('org unit created without functional reporting has no relationship record', function (): void {
    $user = ouRelTestAdmin();
    $org = ouRelTestOrganization('no-reporting');

    $unitType = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        ['prefix' => 'OFF', 'name_en' => 'Office', 'sort_order' => 1, 'is_active' => true],
    );

    $code = 'OUREL-NR-' . uniqid();

    $this->actingAs($user)
        ->post(route('institution-offices.store'), [
            'organization_id' => $org->id,
            'organization_unit_type_id' => $unitType->id,
            'code' => $code,
            'name_en' => 'No Reporting Relationship Office',
            'status' => 'active',
        ])
        ->assertRedirect();

    $unit = OrganizationUnit::query()->where('code', $code)->firstOrFail();

    expect(
        OrganizationUnitRelationship::query()
            ->where('source_unit_id', $unit->id)
            ->count()
    )->toBe(0);
});

// ── Target() method on relationship resolves correct model ────────────────

test('organization unit relationship target resolves to the correct organization model', function (): void {
    $structuralOrg = ouRelTestOrganization('target-test-s');
    $reportingOrg = ouRelTestOrganization('target-test-r');

    $unitType = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        ['prefix' => 'OFF', 'name_en' => 'Office', 'sort_order' => 1, 'is_active' => true],
    );

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $structuralOrg->id,
        'organization_unit_type_id' => $unitType->id,
        'unit_type' => 'office',
        'code' => 'OUREL-TGT-' . uniqid(),
        'name_en' => 'Target Resolution Test Unit',
        'status' => 'active',
    ]);

    $relationship = OrganizationUnitRelationship::query()->create([
        'source_unit_id' => $unit->id,
        'target_type' => RelationshipTargetType::Organization->value,
        'target_id' => $reportingOrg->id,
        'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
        'is_primary' => false,
        'status' => RelationshipStatus::Active->value,
    ]);

    $resolved = $relationship->target();

    expect($resolved)->not->toBeNull();
    expect($resolved->getKey())->toBe($reportingOrg->id);
});
