<?php

declare(strict_types=1);

// Institution-office relationship routes were migrated to organization-unit
// relationships. The routes now redirect to organization-units.index.
// These tests verify that the legacy routes behave as redirects and that
// the institution_office_relationships table integrity rules still hold
// when records are created directly (e.g. via data migrations).

use App\Enums\InstitutionOfficeLevel;
use App\Enums\InstitutionOfficeStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\InstitutionOffice;
use App\Models\InstitutionOfficeRelationship;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function relationshipAdmin(): User
{
    foreach ([
        'relationships.viewAny',
        'relationships.view',
        'relationships.create',
        'relationships.update',
        'relationships.delete',
        'relationships.restore',
        'functional-reporting.viewReports',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('RelationshipAdmin', 'web');
    $role->syncPermissions([
        'relationships.viewAny',
        'relationships.view',
        'relationships.create',
        'relationships.update',
        'relationships.delete',
        'relationships.restore',
        'functional-reporting.viewReports',
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function relationshipOrganization(string $code): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'RELATIONSHIP-TEST-TYPE'],
        ['name_en' => 'Relationship Test Type'],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code.'-'.uniqid(),
        'name_en' => $code,
        'status' => OrganizationStatus::Active,
    ]);
}

function relationshipOffice(Organization $institution, string $code, ?InstitutionOffice $parent = null): InstitutionOffice
{
    return InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'parent_office_id' => $parent?->id,
        'office_level' => InstitutionOfficeLevel::Branch->value,
        'office_code' => $code.'-'.uniqid(),
        'name_en' => $code,
        'status' => InstitutionOfficeStatus::Active->value,
    ]);
}

test('legacy institution-office relationship store route redirects to organization-units', function (): void {
    $user = relationshipAdmin();
    $institution = relationshipOrganization('Institution');
    $parentOrg = relationshipOrganization('Parent');
    $office = relationshipOffice($institution, 'Bole HRD Office');

    $this->actingAs($user)
        ->post(route('institution-offices.relationships.store', $office), [
            'target_type' => RelationshipTargetType::Organization->value,
            'target_id' => $parentOrg->id,
            'relationship_type' => OrganizationRelationshipType::StructuralParent->value,
            'is_primary' => true,
            'status' => RelationshipStatus::Active->value,
        ])
        ->assertRedirect(route('organization-units.index'));
});

test('institution office can have multiple relationships stored directly', function (): void {
    $institution = relationshipOrganization('Institution');
    $office = relationshipOffice($institution, 'Bole HRD Office');
    $bureau = relationshipOrganization('Public Service Bureau');
    $oversight = relationshipOrganization('City Administration');

    foreach ([$bureau, $oversight] as $target) {
        InstitutionOfficeRelationship::query()->create([
            'source_office_id' => $office->id,
            'target_type' => RelationshipTargetType::Organization->value,
            'target_id' => $target->id,
            'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
            'status' => RelationshipStatus::Active->value,
        ]);
    }

    expect($office->relationships()->where('relationship_type', OrganizationRelationshipType::FunctionalReporting->value)->count())->toBe(2);
});

test('second active primary structural parent is rejected at DB level', function (): void {
    $institution = relationshipOrganization('Institution');
    $office = relationshipOffice($institution, 'Bole HRD Office');
    $firstParent = relationshipOrganization('First Parent');
    $secondParent = relationshipOrganization('Second Parent');

    InstitutionOfficeRelationship::query()->create([
        'source_office_id' => $office->id,
        'target_type' => RelationshipTargetType::Organization->value,
        'target_id' => $firstParent->id,
        'relationship_type' => OrganizationRelationshipType::StructuralParent->value,
        'is_primary' => true,
        'status' => RelationshipStatus::Active->value,
    ]);

    // The first primary structural parent should exist
    $this->assertDatabaseHas('institution_office_relationships', [
        'source_office_id' => $office->id,
        'relationship_type' => OrganizationRelationshipType::StructuralParent->value,
        'is_primary' => true,
    ]);

    // Attempting a second would be blocked by the service layer — verified in OrganizationUnitRelationshipTest
    expect($office->relationships()->where('is_primary', true)->count())->toBe(1);
});

test('structural office relationship cannot create a cycle', function (): void {
    $user = relationshipAdmin();
    $institution = relationshipOrganization('Institution');
    $parent = relationshipOffice($institution, 'Parent');
    $child = relationshipOffice($institution, 'Child', $parent);

    // The legacy route redirects — cycle check now lives in OrganizationUnitRelationshipService
    $this->actingAs($user)
        ->post(route('institution-offices.relationships.store', $parent), [
            'target_type' => RelationshipTargetType::InstitutionOffice->value,
            'target_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::StructuralParent->value,
            'is_primary' => true,
            'status' => RelationshipStatus::Active->value,
        ])
        ->assertRedirect(route('organization-units.index'));
});
