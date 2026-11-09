<?php

declare(strict_types=1);

use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function unitRelationshipAdmin(): User
{
    foreach (['relationships.viewAny', 'relationships.view', 'relationships.create', 'relationships.update'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('UnitRelationshipAdmin', 'web');
    $role->syncPermissions(['relationships.viewAny', 'relationships.view', 'relationships.create', 'relationships.update']);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function unitRelationshipOrganization(string $code): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'UNIT-RELATIONSHIP-TEST-TYPE'],
        ['name_en' => 'Unit Relationship Test Type'],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code.'-'.uniqid(),
        'name_en' => $code,
        'status' => OrganizationStatus::Active,
    ]);
}

function relationshipUnit(Organization $organization, string $code, ?OrganizationUnit $parent = null): OrganizationUnit
{
    return OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'parent_unit_id' => $parent?->id,
        'unit_type' => 'directorate',
        'code' => $code.'-'.uniqid(),
        'name_en' => $code,
        'status' => OrganizationUnitStatus::Active->value,
    ]);
}

test('organization unit can have multiple reporting relationships', function (): void {
    $user = unitRelationshipAdmin();
    $organization = unitRelationshipOrganization('Office Owner');
    $unit = relationshipUnit($organization, 'HR Directorate');
    $targetA = unitRelationshipOrganization('Public Service Bureau');
    $targetB = unitRelationshipOrganization('City Administration');

    foreach ([$targetA, $targetB] as $target) {
        $this->actingAs($user)->post(route('organization-units.relationships.store', $unit), [
            'target_type' => RelationshipTargetType::Organization->value,
            'target_id' => $target->id,
            'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
            'status' => RelationshipStatus::Active->value,
        ])->assertRedirect(route('organization-units.show', $unit));
    }

    expect($unit->relationships()->where('relationship_type', OrganizationRelationshipType::FunctionalReporting->value)->count())->toBe(2);
});

test('organization unit structural cycle is rejected', function (): void {
    $user = unitRelationshipAdmin();
    $organization = unitRelationshipOrganization('Office Owner');
    $parent = relationshipUnit($organization, 'Parent Unit');
    $child = relationshipUnit($organization, 'Child Unit', $parent);

    $this->actingAs($user)->post(route('organization-units.relationships.store', $parent), [
        'target_type' => RelationshipTargetType::OrganizationUnit->value,
        'target_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::StructuralParent->value,
        'is_primary' => true,
        'status' => RelationshipStatus::Active->value,
    ])->assertSessionHasErrors(['target_id']);
});
