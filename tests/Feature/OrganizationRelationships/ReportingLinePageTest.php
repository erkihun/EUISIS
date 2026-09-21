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
use App\Models\OrganizationUnitRelationship;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function reportingLineViewer(): User
{
    foreach (['relationships.viewAny', 'functional-reporting.viewReports'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('ReportingLineViewer', 'web');
    $role->syncPermissions(['relationships.viewAny', 'functional-reporting.viewReports']);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function reportingLineOrganization(string $name): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'REPORTING-LINE-TYPE'],
        ['name_en' => 'Reporting Line Type'],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'RL-'.uniqid(),
        'name_en' => $name,
        'status' => OrganizationStatus::Active,
    ]);
}

function reportingLineUnit(Organization $organization, string $name): OrganizationUnit
{
    return OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'unit_type' => 'directorate',
        'code' => 'RLU-'.uniqid(),
        'name_en' => $name,
        'status' => OrganizationUnitStatus::Active->value,
    ]);
}

function reportingLine(OrganizationUnit $source, Organization $target, OrganizationRelationshipType $type): OrganizationUnitRelationship
{
    return OrganizationUnitRelationship::query()->create([
        'source_unit_id' => $source->id,
        'target_type' => RelationshipTargetType::Organization->value,
        'target_id' => $target->id,
        'relationship_type' => $type->value,
        'status' => RelationshipStatus::Active->value,
    ]);
}

test('the reporting lines screen renders as a page with named source and target', function (): void {
    $user = reportingLineViewer();
    $organization = reportingLineOrganization('Addis Ababa Bureau');
    $unit = reportingLineUnit($organization, 'Human Resource Directorate');
    $target = reportingLineOrganization('Public Service Commission');

    reportingLine($unit, $target, OrganizationRelationshipType::FunctionalReporting);

    $this->actingAs($user)
        ->get(route('reporting-lines.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ReportingLines/Index')
            ->where('lines.meta.total', 1)
            ->where('lines.data.0.source.name_en', 'Human Resource Directorate')
            // Without a resolved target the screen can only show a raw UUID.
            ->where('lines.data.0.target.name_en', 'Public Service Commission')
            ->where('lines.data.0.relationship_type', 'functional_reporting')
            ->where('summary.0.count', 1),
        );
});

test('structural parents are not listed as reporting lines', function (): void {
    $user = reportingLineViewer();
    $organization = reportingLineOrganization('Bureau');
    $unit = reportingLineUnit($organization, 'Finance Directorate');
    $target = reportingLineOrganization('Parent Bureau');

    reportingLine($unit, $target, OrganizationRelationshipType::StructuralParent);

    $this->actingAs($user)
        ->get(route('reporting-lines.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lines.meta.total', 0));
});

test('reporting lines can be filtered by relationship type and source name', function (): void {
    $user = reportingLineViewer();
    $organization = reportingLineOrganization('Bureau');
    $hr = reportingLineUnit($organization, 'Human Resource Directorate');
    $finance = reportingLineUnit($organization, 'Finance Directorate');
    $target = reportingLineOrganization('Commission');

    reportingLine($hr, $target, OrganizationRelationshipType::FunctionalReporting);
    reportingLine($finance, $target, OrganizationRelationshipType::TechnicalSupervision);

    $this->actingAs($user)
        ->get(route('reporting-lines.index', ['relationship_type' => 'technical_supervision']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lines.meta.total', 1)
            ->where('lines.data.0.source.name_en', 'Finance Directorate'));

    $this->actingAs($user)
        ->get(route('reporting-lines.index', ['q' => 'Human Resource']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lines.meta.total', 1)
            ->where('lines.data.0.source.name_en', 'Human Resource Directorate'));

    // An unknown filter value must not silently widen the result set.
    $this->actingAs($user)
        ->get(route('reporting-lines.index', ['relationship_type' => 'not_a_type']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lines.meta.total', 2));
});

test('the json collection is still available to api callers', function (): void {
    $user = reportingLineViewer();
    $organization = reportingLineOrganization('Bureau');
    $unit = reportingLineUnit($organization, 'HR Directorate');
    $target = reportingLineOrganization('Commission');

    reportingLine($unit, $target, OrganizationRelationshipType::FunctionalReporting);

    $this->actingAs($user)
        ->getJson(route('reporting-lines.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.target.name_en', 'Commission');
});

test('a user without either reporting permission is denied', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reporting-lines.index'))
        ->assertForbidden();
});
