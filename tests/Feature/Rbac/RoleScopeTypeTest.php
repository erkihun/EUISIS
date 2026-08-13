<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\RoleScopeType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    foreach (['roles.viewAny', 'roles.create', 'roles.update', 'users.create', 'users.assignRoles'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::query()->updateOrCreate(
        ['name' => 'Super Admin', 'guard_name' => 'web'],
        ['scope_type' => RoleScopeType::Global],
    )->syncPermissions(Permission::all());

    Role::query()->updateOrCreate(
        ['name' => 'Organizational Admin', 'guard_name' => 'web'],
        ['scope_type' => RoleScopeType::Scoped],
    )->syncPermissions(['roles.viewAny', 'roles.create', 'roles.update', 'users.create', 'users.assignRoles']);

    $this->scopeOrganizationType = OrganizationType::query()->create([
        'code' => 'ROLE-SCOPE-TYPE',
        'name_en' => 'Role Scope Type Organization',
    ]);
    $this->scopeOrganization = Organization::query()->create([
        'organization_type_id' => $this->scopeOrganizationType->id,
        'code' => 'ROLE-SCOPE-ORG',
        'name_en' => 'Role Scope Organization',
        'status' => 'active',
    ]);
});

function roleScopeSuperAdmin(): User
{
    return User::factory()->create()->assignRole('Super Admin');
}

function roleScopeOrganizationalAdmin(Organization $organization): User
{
    $user = User::factory()->create()->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
    ]);

    return $user;
}

test('role create page exposes scope type capabilities and defaults stored roles to scoped', function (): void {
    $actor = roleScopeOrganizationalAdmin($this->scopeOrganization);

    $this->actingAs($actor)
        ->get(route('roles.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Roles/Create')
            ->where('can.manageGlobalRoles', false));

    $this->actingAs($actor)
        ->post(route('roles.store'), ['name' => 'Scoped Default Role', 'permissions' => []])
        ->assertSessionHasNoErrors();

    $role = Role::findByName('Scoped Default Role', 'web');
    expect($role->scope_type)->toBe(RoleScopeType::Scoped)
        ->and($role->isScoped())->toBeTrue()
        ->and($role->isGlobal())->toBeFalse();
});

test('organizational admin cannot create a global role', function (): void {
    $actor = roleScopeOrganizationalAdmin($this->scopeOrganization);

    $this->actingAs($actor)
        ->post(route('roles.store'), [
            'name' => 'Forbidden Global Role',
            'scope_type' => 'global',
            'permissions' => [],
        ])
        ->assertSessionHasErrors('scope_type');

    expect(Role::query()->where('name', 'Forbidden Global Role')->exists())->toBeFalse();
});

test('organizational admin cannot update or downgrade an existing global role', function (): void {
    $actor = roleScopeOrganizationalAdmin($this->scopeOrganization);
    $role = Role::query()->create([
        'name' => 'Existing Global Role',
        'guard_name' => 'web',
        'scope_type' => RoleScopeType::Global,
    ]);

    $this->actingAs($actor)
        ->patch(route('roles.update', $role), [
            'name' => $role->name,
            'scope_type' => 'scoped',
            'permissions' => [],
        ])
        ->assertForbidden();

    expect($role->fresh()->isGlobal())->toBeTrue();
});

test('super admin can create and assign a global role without organization scope', function (): void {
    $actor = roleScopeSuperAdmin();

    $this->actingAs($actor)
        ->post(route('roles.store'), [
            'name' => 'Security Settings Manager',
            'scope_type' => 'global',
            'permissions' => [],
        ])
        ->assertSessionHasNoErrors();

    $role = Role::findByName('Security Settings Manager', 'web');
    expect($role->isGlobal())->toBeTrue()
        ->and($role->canBeAssignedBy($actor))->toBeTrue();

    $this->actingAs($actor)
        ->post(route('users.store'), [
            'name' => 'Global Security User',
            'email' => 'global-security@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
            'role_ids' => [$role->id],
        ])
        ->assertSessionHasNoErrors();

    $created = User::query()->where('email', 'global-security@example.test')->firstOrFail();
    expect($created->hasRole($role))->toBeTrue()
        ->and($created->organizationScopes()->exists())->toBeFalse();
});

test('assigning a scoped role requires an organization scope', function (): void {
    $actor = roleScopeSuperAdmin();
    $role = Role::query()->create([
        'name' => 'HR Officer',
        'guard_name' => 'web',
        'scope_type' => RoleScopeType::Scoped,
    ]);

    $this->actingAs($actor)
        ->post(route('users.store'), [
            'name' => 'Unscoped HR User',
            'email' => 'unscoped-hr@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
            'role_ids' => [$role->id],
        ])
        ->assertSessionHasErrors('organization_scope_ids');

    expect(User::query()->where('email', 'unscoped-hr@example.test')->exists())->toBeFalse();
});

test('organizational admin cannot assign a global role', function (): void {
    $actor = roleScopeOrganizationalAdmin($this->scopeOrganization);
    $role = Role::query()->create([
        'name' => 'Global Audit Operator',
        'guard_name' => 'web',
        'scope_type' => RoleScopeType::Global,
    ]);

    $this->actingAs($actor)
        ->post(route('users.store'), [
            'name' => 'Escalated Global User',
            'email' => 'escalated-global@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
            'role_ids' => [$role->id],
            'organization_scope_ids' => [$this->scopeOrganization->id],
            'scope_type' => 'self',
        ])
        ->assertSessionHasErrors('role_ids');

    expect(User::query()->where('email', 'escalated-global@example.test')->exists())->toBeFalse();
});
