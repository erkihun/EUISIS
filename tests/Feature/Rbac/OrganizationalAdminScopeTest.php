<?php

declare(strict_types=1);

use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    // Build the Organizational Admin role with the exact operational permission
    // set the RoleSeeder grants it — inline, so the suite stays fast (running
    // the full catalog seeder per test is prohibitively slow).
    $orgAdminPerms = [
        'dashboard.view',
        'organizations.viewAny', 'organizations.view', 'organizations.manage',
        'organization-units.viewAny', 'organization-units.view',
        'organization-units.create', 'organization-units.update', 'organization-units.archive',
        'positions.viewAny', 'positions.view', 'positions.create', 'positions.update',
        'employees.viewAny', 'employees.view', 'employees.viewPii', 'employees.manage',
        'id-cards.viewAny', 'id-cards.view', 'cards.view', 'cards.manage',
        'users.viewAny', 'users.view', 'users.create', 'users.update', 'users.assignRoles',
        'users.assignOrganizationScopes',
        'user-organization-scopes.viewAny', 'user-organization-scopes.create',
        'reports.view',
    ];

    foreach ($orgAdminPerms as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('City Admin', 'web');
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions($orgAdminPerms);
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function oaOrgType(): OrganizationType
{
    // OrganizationType uppercases `code` on save, so the firstOrCreate lookup
    // must use the already-uppercased value or it never matches the stored row.
    return OrganizationType::query()->firstOrCreate(
        ['code' => 'OA-BUREAU'],
        ['name_en' => 'OA Bureau', 'is_demo' => false],
    );
}

function oaMakeOrg(string $suffix): Organization
{
    return Organization::query()->create([
        'organization_type_id' => oaOrgType()->id,
        'code' => 'OA-'.$suffix.'-'.uniqid(),
        'name_en' => 'OA Org '.$suffix,
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->subDay()->toDateString(),
        'is_demo' => false,
    ]);
}

function oaPublishParentChild(Organization $parent, Organization $child): void
{
    $version = HierarchyVersion::query()->create([
        'version_name' => 'oa-v-'.uniqid(),
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
}

/** An Organizational Admin scoped to $org (direct by default, subtree optional). */
function oaOrgAdminFor(Organization $org, OrganizationScopeType $type = OrganizationScopeType::Self): User
{
    $user = User::factory()->create();
    $user->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scope_type' => $type,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

function oaEmployeeIn(Organization $org): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => 'OA-EMP-'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'full_name' => 'Test Employee',
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

// ── 1–2: Organization access ─────────────────────────────────────────────────

test('Organizational Admin can view their own scoped organization', function (): void {
    $org = oaMakeOrg('own');
    $admin = oaOrgAdminFor($org);

    actingAs($admin)->get(route('organizations.show', $org))->assertOk();
});

test('Organizational Admin cannot view another organization', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);

    actingAs($admin)->get(route('organizations.show', $other))->assertForbidden();
});

test('Organizational Admin cannot update another organization (route model binding cannot bypass scope)', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);

    actingAs($admin)
        ->patch(route('organizations.update', $other), ['name_en' => 'Hijacked'])
        ->assertForbidden();

    expect($other->fresh()->name_en)->not->toBe('Hijacked');
});

test('Organizational Admin cannot archive another organization', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);

    actingAs($admin)->delete(route('organizations.archive', $other))->assertForbidden();
});

// ── 3–4: Organization units ──────────────────────────────────────────────────

test('Organizational Admin can manage a unit inside scope', function (): void {
    $org = oaMakeOrg('own');
    $admin = oaOrgAdminFor($org);
    $scope = app(OrganizationScopeService::class);

    expect($scope->canManageWithinScope($admin, $org))->toBeTrue();
});

test('Organizational Admin cannot view a unit outside scope', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $other->id,
        'unit_type' => 'unit',
        'code' => 'OA-UNIT-'.uniqid(),
        'name_en' => 'Outside Unit',
        'status' => 'active',
    ]);

    actingAs($admin)->get(route('organization-units.show', $unit))->assertForbidden();
});

test('Organizational Admin cannot create a unit in an out-of-scope organization', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);

    actingAs($admin)
        ->post(route('organization-units.store'), [
            'organization_id' => $other->id,
            'name_en' => 'Sneaky Unit',
            'status' => 'active',
        ])
        ->assertSessionHasErrors('organization_id');

    expect(OrganizationUnit::query()->where('name_en', 'Sneaky Unit')->exists())->toBeFalse();
});

// ── 5–6: Employees ───────────────────────────────────────────────────────────

test('Organizational Admin can view an employee inside scope', function (): void {
    $org = oaMakeOrg('own');
    $admin = oaOrgAdminFor($org);
    $employee = oaEmployeeIn($org);

    actingAs($admin)->get(route('employees.show', $employee))->assertOk();
});

test('Organizational Admin cannot view an employee outside scope', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);
    $employee = oaEmployeeIn($other);

    actingAs($admin)->get(route('employees.show', $employee))->assertForbidden();
});

// ── 7: Positions ─────────────────────────────────────────────────────────────

test('Organizational Admin cannot view a position outside scope', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);

    $position = Position::query()->create([
        'organization_id' => $other->id,
        'job_position_code' => 'OA-POS-'.uniqid(),
        'title_en' => 'Outside Position',
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ]);

    actingAs($admin)->get(route('positions.show', $position))->assertForbidden();
});

// ── 8: ID cards ──────────────────────────────────────────────────────────────

test('Organizational Admin cannot view an ID card outside scope', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);
    $employee = oaEmployeeIn($other);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'OA-CARD-'.uniqid(),
        'status' => CardStatus::PendingPrint,
        'is_current' => true,
    ]);

    actingAs($admin)->get(route('id-cards.show', $card))->assertForbidden();
});

// ── 9: Citywide/security settings ────────────────────────────────────────────

test('Organizational Admin cannot access system security settings', function (): void {
    $org = oaMakeOrg('own');
    $admin = oaOrgAdminFor($org);

    actingAs($admin)->get(route('system-settings.index'))->assertForbidden();
});

test('Organizational Admin cannot manage roles or permissions pages', function (): void {
    $org = oaMakeOrg('own');
    $admin = oaOrgAdminFor($org);

    actingAs($admin)->get(route('roles.index'))->assertForbidden();
    actingAs($admin)->get(route('permissions.index'))->assertForbidden();
});

// ── 10–11: Role & scope assignment limits ────────────────────────────────────

/** A regular user scoped to $org so an Organizational Admin may manage them. */
function oaTargetIn(Organization $org): User
{
    $user = User::factory()->create();

    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

test('Organizational Admin cannot assign the Super Admin role to a scoped user', function (): void {
    $org = oaMakeOrg('own');
    $admin = oaOrgAdminFor($org);
    $target = oaTargetIn($org);

    actingAs($admin)
        ->post(route('users.assign-roles', $target), ['roles' => ['Super Admin']])
        ->assertSessionHasErrors('roles');

    expect($target->fresh()->hasRole('Super Admin'))->toBeFalse();
});

test('Organizational Admin cannot assign the City Admin role to a scoped user', function (): void {
    $org = oaMakeOrg('own');
    $admin = oaOrgAdminFor($org);
    $target = oaTargetIn($org);

    actingAs($admin)
        ->post(route('users.assign-roles', $target), ['roles' => ['City Admin']])
        ->assertSessionHasErrors('roles');

    expect($target->fresh()->hasRole('City Admin'))->toBeFalse();
});

test('Organizational Admin cannot manage a user outside their scope at all', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);
    $target = oaTargetIn($other);

    actingAs($admin)
        ->post(route('users.assign-roles', $target), ['roles' => ['Organizational Admin']])
        ->assertForbidden();
});

test('Organizational Admin cannot assign an organization scope outside their own', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);
    $target = User::factory()->create();

    actingAs($admin)
        ->post(route('users.organization-scopes.store', $target), [
            'organization_id' => $other->id,
            'scope_type' => 'self',
        ])
        ->assertSessionHasErrors('organization_id');

    expect($target->organizationScopes()->count())->toBe(0);
});

// ── 12–13: Subtree vs direct scope ───────────────────────────────────────────

test('subtree scope includes child organizations', function (): void {
    $parent = oaMakeOrg('parent');
    $child = oaMakeOrg('child');
    oaPublishParentChild($parent, $child);

    $admin = oaOrgAdminFor($parent, OrganizationScopeType::Subtree);
    $scope = app(OrganizationScopeService::class);

    expect($scope->canAccess($admin, $child))->toBeTrue()
        ->and($scope->canAccess($admin, $parent))->toBeTrue();
});

test('direct (self) scope does not include child organizations', function (): void {
    $parent = oaMakeOrg('parent');
    $child = oaMakeOrg('child');
    oaPublishParentChild($parent, $child);

    $admin = oaOrgAdminFor($parent, OrganizationScopeType::Self);
    $scope = app(OrganizationScopeService::class);

    expect($scope->canAccess($admin, $parent))->toBeTrue()
        ->and($scope->canAccess($admin, $child))->toBeFalse();
});

// ── 14: applyOrganizationScope query filter ──────────────────────────────────

test('applyOrganizationScope filters a query to the actor scope', function (): void {
    $own = oaMakeOrg('own');
    $other = oaMakeOrg('other');
    $admin = oaOrgAdminFor($own);

    $ids = app(OrganizationScopeService::class)
        ->applyOrganizationScope(Organization::query(), $admin, 'id')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($own->id)
        ->and($ids)->not->toContain($other->id);
});

// ── Super Admin regression ───────────────────────────────────────────────────

test('Super Admin retains unrestricted access to any organization', function (): void {
    $a = oaMakeOrg('a');
    $b = oaMakeOrg('b');
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    actingAs($superAdmin)->get(route('organizations.show', $a))->assertOk();
    actingAs($superAdmin)->get(route('organizations.show', $b))->assertOk();
});
