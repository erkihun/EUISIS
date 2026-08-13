<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.create', 'organizations.update', 'organizations.delete'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(['organizations.view', 'organizations.create', 'organizations.update', 'organizations.delete']);
    Role::findOrCreate('Organization Manager', 'web')->givePermissionTo(['organizations.view', 'organizations.create', 'organizations.update', 'organizations.delete']);
    Role::findOrCreate('Viewer', 'web')->givePermissionTo(['organizations.view']);
});

function createOrganizationType(): OrganizationType
{
    return OrganizationType::query()->create([
        'code' => 'dept',
        'name_en' => 'Department',
    ]);
}

function createHierarchyVersion(string $name, HierarchyVersionStatus $status): HierarchyVersion
{
    return HierarchyVersion::query()->create([
        'version_name' => $name,
        'status' => $status,
        'effective_from' => now()->toDateString(),
    ]);
}

function createOrganization(OrganizationType $type, string $code, string $name, OrganizationStatus $status = OrganizationStatus::Active): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => $name,
        'status' => $status,
        'effective_from' => now()->toDateString(),
    ]);
}

function ensureSelfPath(HierarchyVersion $version, Organization $organization): void
{
    OrganizationClosurePath::query()->firstOrCreate([
        'hierarchy_version_id' => $version->id,
        'ancestor_organization_id' => $organization->id,
        'descendant_organization_id' => $organization->id,
    ], [
        'depth' => 0,
    ]);
}

function attachHierarchy(HierarchyVersion $version, Organization $parent, Organization $child): void
{
    ensureSelfPath($version, $parent);
    ensureSelfPath($version, $child);

    OrganizationEdge::query()->create([
        'hierarchy_version_id' => $version->id,
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);

    $ancestorPaths = OrganizationClosurePath::query()
        ->where('hierarchy_version_id', $version->id)
        ->where('descendant_organization_id', $parent->id)
        ->get(['ancestor_organization_id', 'depth']);

    foreach ($ancestorPaths as $ancestorPath) {
        OrganizationClosurePath::query()->firstOrCreate([
            'hierarchy_version_id' => $version->id,
            'ancestor_organization_id' => $ancestorPath->ancestor_organization_id,
            'descendant_organization_id' => $child->id,
        ], [
            'depth' => $ancestorPath->depth + 1,
        ]);
    }
}

function superAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function scopedManager(Organization $organization): User
{
    $user = User::factory()->create();
    $user->assignRole('Organization Manager');

    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'scope_type' => OrganizationScopeType::Subtree,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user;
}

test('create page returns multiple eligible parent organizations for super admin', function (): void {
    $type = createOrganizationType();

    createOrganization($type, 'ORG-ROOT', 'Root Organization');
    createOrganization($type, 'ORG-FIN', 'Finance Bureau');
    createOrganization($type, 'ORG-HR', 'Human Resources');
    createOrganization($type, 'ORG-OLD', 'Archived Office', OrganizationStatus::Archived);

    $this->actingAs(superAdmin())
        ->get(route('organizations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Create')
            ->has('parentOrganizationOptions', 3)
            ->where('parentOrganizationOptions.0.can_create_child', true)
        );
});

test('parent options endpoint applies subtree scope filtering', function (): void {
    $type = createOrganizationType();
    $published = createHierarchyVersion('published-v1', HierarchyVersionStatus::Published);

    $root = createOrganization($type, 'ORG-ROOT', 'Root Organization');
    $finance = createOrganization($type, 'ORG-FIN', 'Finance Bureau');
    $hr = createOrganization($type, 'ORG-HR', 'Human Resources');

    attachHierarchy($published, $root, $finance);
    attachHierarchy($published, $root, $hr);

    $user = scopedManager($finance);

    $this->actingAs($user)
        ->getJson(route('organizations.parent-options'))
        ->assertOk()
        ->assertJsonCount(1, 'options')
        ->assertJsonPath('options.0.id', $finance->id);
});

test('users without organization management cannot access parent options endpoint', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Viewer');

    $this->actingAs($user)
        ->getJson(route('organizations.parent-options'))
        ->assertForbidden();
});

test('parent options endpoint supports search and preserves selected parent', function (): void {
    $type = createOrganizationType();
    $finance = createOrganization($type, 'ORG-FIN', 'Finance Bureau');
    createOrganization($type, 'ORG-HR', 'Human Resources');

    $this->actingAs(superAdmin())
        ->getJson(route('organizations.parent-options', [
            'q' => 'Finance',
            'selected_id' => $finance->id,
        ]))
        ->assertOk()
        ->assertJsonPath('selected.id', $finance->id)
        ->assertJsonPath('options.0.id', $finance->id);
});

test('parent options exclude inactive and current organizations', function (): void {
    $type = createOrganizationType();
    $current = createOrganization($type, 'ORG-CURRENT', 'Current Organization');
    $inactive = createOrganization($type, 'ORG-INACTIVE', 'Inactive Organization', OrganizationStatus::Inactive);
    $active = createOrganization($type, 'ORG-ACTIVE', 'Active Organization');

    $response = $this->actingAs(superAdmin())->getJson(route('organizations.parent-options', [
        'selected_id' => $inactive->id,
        'current_organization_id' => $current->id,
    ]));

    $response->assertOk()->assertJsonPath('selected', null);
    expect(collect($response->json('options'))->pluck('id')->all())
        ->toBe([$active->id]);
});

test('creating a child rejects an inactive parent organization', function (): void {
    $type = createOrganizationType();
    $draft = createHierarchyVersion('draft-inactive-parent', HierarchyVersionStatus::Draft);
    $parent = createOrganization($type, 'ORG-INACTIVE-PARENT', 'Inactive Parent', OrganizationStatus::Inactive);

    $this->actingAs(superAdmin())
        ->post(route('organizations.store'), [
            'organization_type_id' => $type->id,
            'code' => 'ORG-INVALID-CHILD',
            'name_en' => 'Invalid Child',
            'status' => 'active',
            'parent_organization_id' => $parent->id,
            'hierarchy_version_id' => $draft->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
        ])
        ->assertSessionHasErrors('parent_organization_id');
});

test('creating a child rejects an invalid parent type relationship', function (): void {
    $parentType = OrganizationType::query()->create(['code' => 'PARENT-A', 'name_en' => 'Parent A']);
    $childType = OrganizationType::query()->create([
        'code' => 'CHILD-B',
        'name_en' => 'Child B',
        'parent_allowed_types' => ['PARENT-B'],
    ]);
    $draft = createHierarchyVersion('draft-invalid-parent-type', HierarchyVersionStatus::Draft);
    $parent = createOrganization($parentType, 'ORG-PARENT-A', 'Parent A');

    $this->actingAs(superAdmin())
        ->post(route('organizations.store'), [
            'organization_type_id' => $childType->id,
            'code' => 'ORG-CHILD-B',
            'name_en' => 'Child B',
            'status' => 'active',
            'parent_organization_id' => $parent->id,
            'hierarchy_version_id' => $draft->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
        ])
        ->assertSessionHasErrors('organization_type_id');
});

test('creating child organization under valid scoped parent creates organization edge and audit log', function (): void {
    $type = createOrganizationType();
    $draft = createHierarchyVersion('draft-v2', HierarchyVersionStatus::Draft);
    $published = createHierarchyVersion('published-v1', HierarchyVersionStatus::Published);

    $root = createOrganization($type, 'ORG-ROOT', 'Root Organization');
    $finance = createOrganization($type, 'ORG-FIN', 'Finance Bureau');

    attachHierarchy($published, $root, $finance);

    $user = scopedManager($finance);

    $response = $this->actingAs($user)->post(route('organizations.store'), [
        'organization_type_id' => $type->id,
        'code' => 'ORG-FIN-CHILD',
        'name_en' => 'Finance Child',
        'status' => OrganizationStatus::Active->value,
        'parent_organization_id' => $finance->id,
        'hierarchy_version_id' => $draft->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
    ]);

    $child = Organization::query()->where('code', 'ORG-FIN-CHILD')->firstOrFail();

    $response->assertRedirect(route('organizations.show', $finance));
    $this->assertDatabaseHas('organization_edges', [
        'hierarchy_version_id' => $draft->id,
        'parent_organization_id' => $finance->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::OrganizationCreated->value,
        'auditable_id' => $child->id,
    ]);
});

test('creating child organization rejects parent outside scoped organization subtree', function (): void {
    $type = createOrganizationType();
    $draft = createHierarchyVersion('draft-v2', HierarchyVersionStatus::Draft);
    $published = createHierarchyVersion('published-v1', HierarchyVersionStatus::Published);

    $root = createOrganization($type, 'ORG-ROOT', 'Root Organization');
    $finance = createOrganization($type, 'ORG-FIN', 'Finance Bureau');
    $hr = createOrganization($type, 'ORG-HR', 'Human Resources');

    attachHierarchy($published, $root, $finance);
    attachHierarchy($published, $root, $hr);

    $user = scopedManager($finance);

    $this->actingAs($user)
        ->from(route('organizations.create'))
        ->post(route('organizations.store'), [
            'organization_type_id' => $type->id,
            'code' => 'ORG-HR-CHILD',
            'name_en' => 'HR Child',
            'status' => OrganizationStatus::Active->value,
            'parent_organization_id' => $hr->id,
            'hierarchy_version_id' => $draft->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
        ])
        ->assertRedirect(route('organizations.create'))
        ->assertSessionHasErrors('parent_organization_id');
});

test('creating child organization requires a draft hierarchy version', function (): void {
    $type = createOrganizationType();
    $published = createHierarchyVersion('published-v1', HierarchyVersionStatus::Published);
    $root = createOrganization($type, 'ORG-ROOT', 'Root Organization');

    $this->actingAs(superAdmin())
        ->from(route('organizations.create'))
        ->post(route('organizations.store'), [
            'organization_type_id' => $type->id,
            'code' => 'ORG-CHILD',
            'name_en' => 'Child Org',
            'status' => OrganizationStatus::Active->value,
            'parent_organization_id' => $root->id,
            'hierarchy_version_id' => $published->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
        ])
        ->assertRedirect(route('organizations.create'))
        ->assertSessionHasErrors('hierarchy_version_id');
});
