<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\CodeRule;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function simplifiedCreateAdmin(): User
{
    foreach ([
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('SimplifiedInstitutionOfficeCreator', 'web');
    $role->syncPermissions([
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function simplifiedCreateOrganization(string $name): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'SIMPLIFIED-OFFICE-TEST-TYPE'],
        ['name_en' => 'Simplified Office Test Type'],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => strtoupper(substr($name, 0, 3)).'-'.uniqid(),
        'name_en' => $name,
        'status' => OrganizationStatus::Active,
    ]);
}

function simplifiedCreateOfficeUnitType(): OrganizationUnitType
{
    return OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        [
            'prefix' => 'OFF',
            'name_en' => 'Office',
            'sort_order' => 1,
            'is_active' => true,
        ],
    );
}

function simplifiedCreateCodeRule(): CodeRule
{
    return CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::OrganizationUnit->value,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::OrganizationUnit),
        'name_en' => 'Organization Unit Code',
        'prefix' => 'OU',
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'sequence_length' => 4,
        'next_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'is_active' => true,
        'allow_manual_override' => true,
        'require_approval_for_override' => false,
    ]);
}

test('institution office create route creates organization unit under selected organization', function (): void {
    $user = simplifiedCreateAdmin();
    $organization = simplifiedCreateOrganization('Bole Sub-city Administration');
    $unitType = simplifiedCreateOfficeUnitType();
    $code = 'BOLE-HRD-'.uniqid();

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $organization->id,
        'organization_unit_type_id' => $unitType->id,
        'code' => $code,
        'name_en' => 'Bole Sub-city Public Service and HRD Office',
        'status' => 'active',
    ])->assertRedirect();

    $this->assertDatabaseHas('organization_units', [
        'organization_id' => $organization->id,
        'organization_unit_type_id' => $unitType->id,
        'unit_type' => 'office',
        'code' => $code,
        'name_en' => 'Bole Sub-city Public Service and HRD Office',
    ]);

    $this->assertDatabaseMissing('institution_offices', [
        'name_en' => 'Bole Sub-city Public Service and HRD Office',
    ]);
});

test('institution office create route creates optional functional reporting relationship', function (): void {
    $user = simplifiedCreateAdmin();
    $organization = simplifiedCreateOrganization('Bole Sub-city Administration');
    $reportingOrganization = simplifiedCreateOrganization('Public Service and HRD Bureau');
    $unitType = simplifiedCreateOfficeUnitType();

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $organization->id,
        'organization_unit_type_id' => $unitType->id,
        'code' => 'FUNC-'.uniqid(),
        'name_en' => 'Bole Sub-city Public Service and HRD Office',
        'functional_reporting_organization_id' => $reportingOrganization->id,
        'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
        'status' => 'active',
    ])->assertRedirect();

    $unit = OrganizationUnit::query()->where('name_en', 'Bole Sub-city Public Service and HRD Office')->firstOrFail();

    $this->assertDatabaseHas('organization_unit_relationships', [
        'source_unit_id' => $unit->id,
        'target_type' => RelationshipTargetType::Organization->value,
        'target_id' => $reportingOrganization->id,
        'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
        'is_primary' => false,
        'status' => RelationshipStatus::Active->value,
    ]);
});

test('parent organization unit must belong to same structural organization', function (): void {
    $user = simplifiedCreateAdmin();
    $firstOrganization = simplifiedCreateOrganization('Bole Sub-city Administration');
    $secondOrganization = simplifiedCreateOrganization('Yeka Sub-city Administration');
    $unitType = simplifiedCreateOfficeUnitType();

    $parent = OrganizationUnit::query()->create([
        'organization_id' => $firstOrganization->id,
        'organization_unit_type_id' => $unitType->id,
        'unit_type' => 'office',
        'code' => 'PARENT-'.uniqid(),
        'name_en' => 'Parent Office',
        'status' => 'active',
    ]);

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $secondOrganization->id,
        'organization_unit_type_id' => $unitType->id,
        'parent_unit_id' => $parent->id,
        'code' => 'CHILD-'.uniqid(),
        'name_en' => 'Child Office',
        'status' => 'active',
    ])->assertSessionHasErrors(['parent_unit_id']);
});

test('office code auto generates when blank', function (): void {
    $user = simplifiedCreateAdmin();
    $organization = simplifiedCreateOrganization('Bole Sub-city Administration');
    $unitType = simplifiedCreateOfficeUnitType();
    simplifiedCreateCodeRule();

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $organization->id,
        'organization_unit_type_id' => $unitType->id,
        'name_en' => 'Auto Coded Office',
        'status' => 'active',
    ])->assertRedirect();

    $this->assertDatabaseHas('organization_units', [
        'organization_id' => $organization->id,
        'code' => 'OU-0001',
        'name_en' => 'Auto Coded Office',
    ]);
});
