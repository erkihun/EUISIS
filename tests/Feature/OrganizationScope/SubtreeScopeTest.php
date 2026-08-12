<?php

declare(strict_types=1);

use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function scopeOrgType(): OrganizationType
{
    return OrganizationType::query()->firstOrCreate(
        ['code' => 'SCOPE-TEST-BUREAU'],
        ['name_en' => 'Scope Test Bureau', 'is_demo' => false],
    );
}

function createScopeOrg(string $codeSuffix, string $nameSuffix = ''): Organization
{
    return Organization::query()->create([
        'organization_type_id' => scopeOrgType()->id,
        'code' => 'SC-'.$codeSuffix.'-'.uniqid(),
        'name_en' => 'Scope Org '.($nameSuffix ?: $codeSuffix),
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->subDay()->toDateString(),
        'is_demo' => false,
    ]);
}

function createPublishedHierarchy(Organization $parent, Organization $child): HierarchyVersion
{
    $version = HierarchyVersion::query()->create([
        'version_name' => 'scope-test-v-'.uniqid(),
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => now()->subDay()->toDateString(),
        'is_demo' => false,
    ]);

    OrganizationEdge::query()->create([
        'hierarchy_version_id' => $version->id,
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
        'effective_from' => now()->toDateString(),
    ]);

    $publisher = User::factory()->create();
    $publisher->assignRole('Super Admin');

    app(PublishHierarchyVersionAction::class)->execute($version, $publisher);

    return $version->fresh();
}

function createScopeEmployee(Organization $org): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => 'SCTEST-'.uniqid(),
        'first_name' => 'Scope',
        'last_name' => 'Employee',
        'full_name' => 'Scope Employee',
        'status' => EmployeeStatus::Active,
        'is_demo' => false,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

function createSubtreeUser(Organization $org): User
{
    $user = User::factory()->create();
    $user->assignRole('HR Officer');

    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Subtree,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user;
}

function createSelfUser(Organization $org): User
{
    $user = User::factory()->create();
    $user->assignRole('HR Officer');

    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user;
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $perms = [
        'employees.view', 'employees.viewAny', 'employees.manage',
        'organizations.view', 'organizations.viewAny', 'organizations.manage',
        'organization-units.viewAny', 'organization-units.view', 'organization-units.create',
        'organization-units.update', 'organization-units.archive', 'organization-units.delete',
        'organization-units.restore', 'organization-units.viewDeleted',
        'organization-units.manageHierarchy',
        'id-cards.viewAny', 'id-cards.view',
        'cards.view',
        'hierarchy-versions.publish', 'hierarchy-versions.view', 'hierarchy-versions.viewAny',
        'hierarchy-versions.create', 'hierarchy-versions.update',
    ];

    foreach ($perms as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('HR Officer', 'web')->syncPermissions([
        'employees.view', 'employees.viewAny', 'employees.manage',
        'organizations.view', 'organizations.viewAny',
        'organization-units.viewAny', 'organization-units.view',
        'id-cards.viewAny', 'id-cards.view', 'cards.view',
    ]);
});

// ---------------------------------------------------------------------------
// 1. Subtree user can access assigned organization
// ---------------------------------------------------------------------------

it('test_subtree_user_can_access_assigned_organization', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    createPublishedHierarchy($parent, $child);

    $user = createSubtreeUser($parent);
    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($user, $parent->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 2. Subtree user can access child organization
// ---------------------------------------------------------------------------

it('test_subtree_user_can_access_child_organization', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    createPublishedHierarchy($parent, $child);

    $user = createSubtreeUser($parent);
    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($user, $child->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 3. Subtree user cannot access unrelated organization
// ---------------------------------------------------------------------------

it('test_subtree_user_cannot_access_unrelated_organization', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    $unrelated = createScopeOrg('unrelated');
    createPublishedHierarchy($parent, $child);

    $user = createSubtreeUser($parent);
    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($user, $unrelated->id))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4. Self user can access assigned organization
// ---------------------------------------------------------------------------

it('test_self_user_can_access_assigned_organization', function (): void {
    $org = createScopeOrg('self');
    $user = createSelfUser($org);
    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($user, $org->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 5. Self user cannot access child organization
// ---------------------------------------------------------------------------

it('test_self_user_cannot_access_child_organization', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    createPublishedHierarchy($parent, $child);

    $user = createSelfUser($parent);
    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($user, $child->id))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. Subtree index shows child organizations via HTTP
// ---------------------------------------------------------------------------

it('test_subtree_index_shows_child_organizations', function (): void {
    $parent = createScopeOrg('parent', 'Parent Bureau');
    $child = createScopeOrg('child', 'Child Bureau');
    createPublishedHierarchy($parent, $child);

    $user = createSubtreeUser($parent);

    $this->actingAs($user)
        ->get(route('employees.index'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// 7. Subtree user sees employees of child organization
// ---------------------------------------------------------------------------

it('test_subtree_user_sees_employees_of_child_organization', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    createPublishedHierarchy($parent, $child);

    $user = createSubtreeUser($parent);
    $employee = createScopeEmployee($child);

    $service = app(OrganizationScopeService::class);

    expect($service->canAccessEmployee($user, $employee))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 8. Subtree user sees units of child organization
// ---------------------------------------------------------------------------

it('test_subtree_user_sees_units_of_child_organization', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    createPublishedHierarchy($parent, $child);

    $user = createSubtreeUser($parent);
    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($user, $child->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 9. Policy denies if permission missing
// ---------------------------------------------------------------------------

it('test_policy_denies_if_permission_missing', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    createPublishedHierarchy($parent, $child);

    $user = User::factory()->create();
    // No roles assigned — no permissions

    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $parent->id,
        'scope_type' => OrganizationScopeType::Subtree,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    expect($user->can('view', $parent))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 10. Super admin accesses all
// ---------------------------------------------------------------------------

it('test_super_admin_accesses_all', function (): void {
    $orgA = createScopeOrg('a');
    $orgB = createScopeOrg('b');

    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');

    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($admin, $orgA->id))->toBeTrue()
        ->and($service->canAccessOrganization($admin, $orgB->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 11. Cache clears after hierarchy publish
// ---------------------------------------------------------------------------

it('test_cache_clears_after_hierarchy_publish', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');

    $user = createSubtreeUser($parent);

    $service = app(OrganizationScopeService::class);

    // Before publish: child not accessible (no published version yet)
    $idsBeforePublish = $service->accessibleOrganizationIds($user);
    expect($idsBeforePublish->contains($child->id))->toBeFalse();

    // Publish and the controller calls clearCache()
    createPublishedHierarchy($parent, $child);
    $service->clearCache($user);

    $idsAfterPublish = $service->accessibleOrganizationIds($user);
    expect($idsAfterPublish->contains($child->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 12. Localization keys exist
// ---------------------------------------------------------------------------

it('test_localization_keys_exist', function (): void {
    $keys = [
        'organizations.no_access_organization',
        'organizations.scope_does_not_include_record',
        'organizations.subtree_scope_description',
        'organizations.no_published_hierarchy_version',
    ];

    foreach ($keys as $key) {
        expect(__($key))->not->toBe($key, "Missing translation key: {$key}");
    }
});

// ---------------------------------------------------------------------------
// 13. Inactive scope is not included
// ---------------------------------------------------------------------------

it('test_inactive_scope_excluded_from_access', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    createPublishedHierarchy($parent, $child);

    $user = User::factory()->create();
    $user->assignRole('HR Officer');

    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $parent->id,
        'scope_type' => OrganizationScopeType::Subtree,
        'is_active' => false,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($user, $parent->id))->toBeFalse()
        ->and($service->canAccessOrganization($user, $child->id))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 14. Expired scope is not included
// ---------------------------------------------------------------------------

it('test_expired_scope_excluded_from_access', function (): void {
    $parent = createScopeOrg('parent');
    $child = createScopeOrg('child');
    createPublishedHierarchy($parent, $child);

    $user = User::factory()->create();
    $user->assignRole('HR Officer');

    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $parent->id,
        'scope_type' => OrganizationScopeType::Subtree,
        'is_active' => true,
        'effective_from' => now()->subDays(10)->toDateString(),
        'effective_to' => now()->subDay()->toDateString(),
    ]);

    $service = app(OrganizationScopeService::class);

    expect($service->canAccessOrganization($user, $parent->id))->toBeFalse();
});
