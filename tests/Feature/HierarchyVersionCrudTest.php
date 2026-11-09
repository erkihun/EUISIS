<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'hierarchy-versions.viewAny',
        'hierarchy-versions.view',
        'hierarchy-versions.create',
        'hierarchy-versions.update',
        'hierarchy-versions.archive',
        'hierarchy-versions.publish',
        'hierarchy-versions.manageTree',
        'organization-edges.view',
        'organization-edges.create',
        'organization-edges.update',
        'organization-edges.remove',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(Permission::all());

    $this->type = OrganizationType::query()->create([
        'code' => 'dept',
        'name_en' => 'Department',
    ]);
});

function hierarchyAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function makeVersion(array $attributes = []): HierarchyVersion
{
    return HierarchyVersion::query()->create(array_merge([
        'version_name' => 'version-'.str()->lower((string) str()->uuid()),
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => now()->toDateString(),
    ], $attributes));
}

function attachDraftEdge(HierarchyVersion $version, OrganizationType $type): void
{
    $parent = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ORG-PARENT-'.str()->upper(str()->random(4)),
        'name_en' => 'Parent Organization',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ORG-CHILD-'.str()->upper(str()->random(4)),
        'name_en' => 'Child Organization',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $version->edges()->create([
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);
}

test('create page requires hierarchy versions create permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['hierarchy-versions.viewAny', 'hierarchy-versions.view']);

    $this->actingAs($user)
        ->get(route('hierarchy-versions.create'))
        ->assertForbidden();
});

test('super admin can create hierarchy version and it defaults to draft', function (): void {
    $user = hierarchyAdmin();

    $this->actingAs($user)
        ->post(route('hierarchy-versions.store'), [
            'version_name' => 'FY 2026 Draft',
            'effective_from' => '2026-05-13',
            'effective_to' => '2026-12-31',
            'source_document' => 'Cabinet Memo 12',
            'notes' => 'Initial draft for 2026 hierarchy review.',
        ])
        ->assertRedirect();

    $version = HierarchyVersion::query()->where('version_name', 'FY 2026 Draft')->firstOrFail();

    expect($version->status)->toBe(HierarchyVersionStatus::Draft);

    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::HierarchyVersionCreated->value,
        'auditable_id' => $version->id,
    ]);
});

test('hierarchy version validation requires name and effective dates are ordered', function (): void {
    $user = hierarchyAdmin();

    $this->actingAs($user)
        ->from(route('hierarchy-versions.create'))
        ->post(route('hierarchy-versions.store'), [
            'version_name' => '',
            'effective_from' => '2026-05-13',
            'effective_to' => '2026-05-01',
        ])
        ->assertRedirect(route('hierarchy-versions.create'))
        ->assertSessionHasErrors(['version_name', 'effective_to']);
});

test('draft version can be edited by authorized user', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion(['version_name' => 'Draft One']);

    $this->actingAs($user)
        ->patch(route('hierarchy-versions.update', $version), [
            'version_name' => 'Draft One Updated',
            'effective_from' => now()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
            'source_document' => 'Revised memo',
            'notes' => 'Updated notes',
        ])
        ->assertRedirect(route('hierarchy-versions.show', $version));

    $this->assertDatabaseHas('hierarchy_versions', [
        'id' => $version->id,
        'version_name' => 'Draft One Updated',
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::HierarchyVersionUpdated->value,
        'auditable_id' => $version->id,
    ]);
});

test('published version cannot be edited', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion(['status' => HierarchyVersionStatus::Published]);

    $this->actingAs($user)
        ->patch(route('hierarchy-versions.update', $version), [
            'version_name' => 'Nope',
            'effective_from' => now()->toDateString(),
        ])
        ->assertForbidden();
});

test('draft version can be published by authorized user', function (): void {
    $user = hierarchyAdmin();
    $existingPublished = makeVersion([
        'version_name' => 'Published V1',
        'status' => HierarchyVersionStatus::Published,
        'effective_from' => now()->subYear()->toDateString(),
    ]);
    $version = makeVersion(['version_name' => 'Draft Publish Me']);
    attachDraftEdge($version, $this->type);

    $this->actingAs($user)
        ->post(route('hierarchy-versions.publish', $version))
        ->assertRedirect(route('hierarchy-versions.show', $version));

    $version->refresh();
    $existingPublished->refresh();

    expect($version->status)->toBe(HierarchyVersionStatus::Published);
    expect($existingPublished->status)->toBe(HierarchyVersionStatus::Archived);

    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::HierarchyPublished->value,
        'auditable_id' => $version->id,
    ]);
});

test('published and archived versions cannot be published again', function (): void {
    $user = hierarchyAdmin();
    $published = makeVersion(['status' => HierarchyVersionStatus::Published]);
    $archived = makeVersion(['status' => HierarchyVersionStatus::Archived]);

    $this->actingAs($user)
        ->post(route('hierarchy-versions.publish', $published))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('hierarchy-versions.publish', $archived))
        ->assertForbidden();
});

test('archive action archives draft version and writes audit log', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion();

    $this->actingAs($user)
        ->post(route('hierarchy-versions.archive', $version))
        ->assertRedirect(route('hierarchy-versions.show', $version));

    $this->assertDatabaseHas('hierarchy_versions', [
        'id' => $version->id,
        'status' => HierarchyVersionStatus::Archived->value,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::HierarchyVersionArchived->value,
        'auditable_id' => $version->id,
    ]);
});

test('hierarchy version translation files exist', function (): void {
    expect(file_exists(lang_path('en/hierarchy-versions.php')))->toBeTrue();
    expect(file_exists(lang_path('am/hierarchy-versions.php')))->toBeTrue();
    expect(file_exists(resource_path('js/i18n/en/hierarchyVersions.ts')))->toBeTrue();
    expect(file_exists(resource_path('js/i18n/am/hierarchyVersions.ts')))->toBeTrue();

    $frontendEn = file_get_contents(resource_path('js/i18n/en/hierarchyVersions.ts'));
    $frontendAm = file_get_contents(resource_path('js/i18n/am/hierarchyVersions.ts'));
    $backendAm = file_get_contents(lang_path('am/hierarchy-versions.php'));

    expect($frontendEn)->toContain('expandAll');
    expect($frontendEn)->toContain('collapseAll');
    expect($frontendEn)->toContain('searchTree');
    expect($frontendAm)->toContain('ሁሉንም ያስፉ');
    expect($frontendAm)->toContain('ዛፉን ይፈልጉ');
    expect($frontendAm)->not->toContain('Ã¡');
    expect($frontendAm)->not->toContain('á‹');
    expect($backendAm)->not->toContain('Ã¡');
    expect($backendAm)->not->toContain('á‹');
});

test('authorized user can access edit tree and receives relation permissions in props', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion(['version_name' => 'Editable Tree']);
    attachDraftEdge($version, $this->type);

    $this->actingAs($user)
        ->get(route('hierarchy-versions.tree.edit', $version))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.manageTree', true)
            ->where('can.createEdge', true)
            ->where('tree.0.depth', 0)
            ->where('tree.0.children.0.depth', 1)
        );
});

test('view tree page receives depth aware tree nodes', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion(['version_name' => 'Viewable Tree']);
    attachDraftEdge($version, $this->type);

    $this->actingAs($user)
        ->get(route('hierarchy-versions.tree', $version))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tree.0.depth', 0)
            ->where('tree.0.children.0.depth', 1)
            ->where('can.manageTree', true)
        );
});

test('user without hierarchy view permission cannot access edit tree', function (): void {
    $user = User::factory()->create();
    $version = makeVersion(['version_name' => 'Restricted Tree']);

    $this->actingAs($user)
        ->get(route('hierarchy-versions.tree.edit', $version))
        ->assertForbidden();
});

test('user without edge management permissions cannot mutate relations', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['hierarchy-versions.view']);
    $version = makeVersion(['version_name' => 'Read Only Tree']);

    $parent = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-ROOT-Z',
        'name_en' => 'Root Z',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-CHILD-Z',
        'name_en' => 'Child Z',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $edge = $version->edges()->create([
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->post(route('hierarchy-versions.edges.store', $version), [
            'parent_organization_id' => $parent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
            'effective_from' => now()->toDateString(),
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('hierarchy-versions.edges.update', [
            'hierarchyVersion' => $version,
            'organizationEdge' => $edge,
        ]), [
            'parent_organization_id' => $parent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::Oversight->value,
            'effective_from' => now()->toDateString(),
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('hierarchy-versions.edges.destroy', [
            'hierarchyVersion' => $version,
            'organizationEdge' => $edge,
        ]))
        ->assertForbidden();
});

test('authorized user can add relationship to draft hierarchy version', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion(['version_name' => 'Draft Tree Editor']);

    $parent = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-ROOT-A',
        'name_en' => 'Root A',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-CHILD-A',
        'name_en' => 'Child A',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->from(route('hierarchy-versions.tree.edit', $version))
        ->post(route('hierarchy-versions.edges.store', $version), [
            'parent_organization_id' => $parent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect(route('hierarchy-versions.tree.edit', $version));

    $this->assertDatabaseHas('organization_edges', [
        'hierarchy_version_id' => $version->id,
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::HierarchyRelationCreated->value,
    ]);
});

test('authorized user can update and remove relationship from draft hierarchy version', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion(['version_name' => 'Draft Tree Updates']);

    $root = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-ROOT-B',
        'name_en' => 'Root B',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-CHILD-B',
        'name_en' => 'Child B',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $newParent = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-ROOT-C',
        'name_en' => 'Root C',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $edge = $version->edges()->create([
        'parent_organization_id' => $root->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->from(route('hierarchy-versions.tree.edit', $version))
        ->patch(route('hierarchy-versions.edges.update', [
            'hierarchyVersion' => $version,
            'organizationEdge' => $edge,
        ]), [
            'parent_organization_id' => $newParent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::Oversight->value,
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect(route('hierarchy-versions.tree.edit', $version));

    $this->assertDatabaseHas('organization_edges', [
        'id' => $edge->id,
        'parent_organization_id' => $newParent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::Oversight->value,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::HierarchyRelationUpdated->value,
    ]);

    $this->actingAs($user)
        ->from(route('hierarchy-versions.tree.edit', $version))
        ->delete(route('hierarchy-versions.edges.destroy', [
            'hierarchyVersion' => $version,
            'organizationEdge' => $edge,
        ]))
        ->assertRedirect(route('hierarchy-versions.tree.edit', $version));

    $this->assertDatabaseMissing('organization_edges', [
        'id' => $edge->id,
    ]);
    $this->assertDatabaseHas('organizations', [
        'id' => $child->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::HierarchyRelationRemoved->value,
    ]);
});

test('duplicate relationship in same hierarchy version is rejected', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion(['version_name' => 'Duplicate Edge Guard']);

    $parent = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-ROOT-D',
        'name_en' => 'Root D',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-CHILD-D',
        'name_en' => 'Child D',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $version->edges()->create([
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->from(route('hierarchy-versions.tree.edit', $version))
        ->post(route('hierarchy-versions.edges.store', $version), [
            'parent_organization_id' => $parent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect(route('hierarchy-versions.tree.edit', $version))
        ->assertSessionHasErrors('child_organization_id');
});

test('circular hierarchy is rejected for draft hierarchy version editor', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion(['version_name' => 'Circular Edge Guard']);

    $root = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-ROOT-E',
        'name_en' => 'Root E',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-CHILD-E',
        'name_en' => 'Child E',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $grandChild = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-GRAND-E',
        'name_en' => 'Grandchild E',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $version->edges()->create([
        'parent_organization_id' => $root->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);

    $version->edges()->create([
        'parent_organization_id' => $child->id,
        'child_organization_id' => $grandChild->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->from(route('hierarchy-versions.tree.edit', $version))
        ->post(route('hierarchy-versions.edges.store', $version), [
            'parent_organization_id' => $grandChild->id,
            'child_organization_id' => $root->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect(route('hierarchy-versions.tree.edit', $version))
        ->assertSessionHasErrors('child_organization_id');
});

test('published hierarchy version editor rejects edge mutations', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion([
        'version_name' => 'Published Readonly Tree',
        'status' => HierarchyVersionStatus::Published,
    ]);

    $parent = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-ROOT-F',
        'name_en' => 'Root F',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-CHILD-F',
        'name_en' => 'Child F',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->post(route('hierarchy-versions.edges.store', $version), [
            'parent_organization_id' => $parent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
            'effective_from' => now()->toDateString(),
        ])
        ->assertForbidden();
});

test('published hierarchy version view tree exposes read only capabilities', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion([
        'version_name' => 'Published Tree View',
        'status' => HierarchyVersionStatus::Published,
    ]);
    attachDraftEdge($version, $this->type);

    $this->actingAs($user)
        ->get(route('hierarchy-versions.tree', $version))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.manageTree', false)
            ->where('can.createEdge', false)
            ->where('tree.0.children.0.can.edit', false)
            ->where('tree.0.children.0.can.remove', false)
        );
});

test('archived hierarchy version editor rejects edge mutations', function (): void {
    $user = hierarchyAdmin();
    $version = makeVersion([
        'version_name' => 'Archived Readonly Tree',
        'status' => HierarchyVersionStatus::Archived,
    ]);

    $parent = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-ROOT-AR',
        'name_en' => 'Root Archived',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $this->type->id,
        'code' => 'ORG-CHILD-AR',
        'name_en' => 'Child Archived',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->post(route('hierarchy-versions.edges.store', $version), [
            'parent_organization_id' => $parent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
            'effective_from' => now()->toDateString(),
        ])
        ->assertForbidden();
});
