<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    foreach ([
        'users.viewAny', 'users.view', 'users.create', 'users.update',
        'users.assignRoles', 'users.assignOrganizationScopes',
        'roles.viewAny', 'roles.create', 'roles.update', 'roles.assignPermissions',
        'user-organization-scopes.create', 'user-organization-scopes.update',
        'system-settings.manageSecurity',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web');

    // A delegated admin who can manage users/roles/scopes but is NOT a Super Admin.
    Role::findOrCreate('Delegated Admin', 'web')->syncPermissions([
        'users.viewAny', 'users.view', 'users.create', 'users.update',
        'users.assignRoles', 'users.assignOrganizationScopes',
        'roles.viewAny', 'roles.create', 'roles.update', 'roles.assignPermissions',
        'user-organization-scopes.create', 'user-organization-scopes.update',
    ]);
});

function escSuperAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('Super Admin');

    return $u;
}

function escDelegatedAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('Delegated Admin');

    return $u;
}

function escMakeOrg(string $status = 'active'): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'DEPT'], ['name_en' => 'Department']);

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ORG-'.fake()->unique()->numerify('####'),
        'name_en' => 'Org '.fake()->unique()->word(),
        'name_am' => 'ድርጅት',
        'status' => $status,
    ]);
}

// ── E1: assigning Super Admin ────────────────────────────────────────────────

test('a delegated admin cannot assign the Super Admin role via assign-roles', function (): void {
    $target = User::factory()->create();

    actingAs(escDelegatedAdmin())
        ->post(route('users.assign-roles', $target), ['roles' => ['Super Admin']])
        ->assertSessionHasErrors('roles');

    expect($target->fresh()->hasRole('Super Admin'))->toBeFalse();
});

test('a Super Admin can assign the Super Admin role', function (): void {
    $target = User::factory()->create();

    actingAs(escSuperAdmin())
        ->post(route('users.assign-roles', $target), ['roles' => ['Super Admin']])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->hasRole('Super Admin'))->toBeTrue();
});

test('a delegated admin cannot assign Super Admin via the user update form', function (): void {
    $target = User::factory()->create(['name' => 'Target', 'email' => 'target@example.test']);

    actingAs(escDelegatedAdmin())
        ->patch(route('users.update', $target), [
            'name' => 'Target',
            'email' => 'target@example.test',
            'roles' => ['Super Admin'],
        ])
        ->assertSessionHasErrors('roles');

    expect($target->fresh()->hasRole('Super Admin'))->toBeFalse();
});

// ── E2: granting permissions the actor does not hold ─────────────────────────

test('a delegated admin cannot create a role carrying a permission they lack', function (): void {
    actingAs(escDelegatedAdmin())
        ->post(route('roles.store'), [
            'name' => 'Sneaky Role',
            'permissions' => ['system-settings.manageSecurity'],
        ])
        ->assertSessionHasErrors('permissions');

    expect(Role::where('name', 'Sneaky Role')->exists())->toBeFalse();
});

test('a delegated admin can create a role with permissions they do hold', function (): void {
    actingAs(escDelegatedAdmin())
        ->post(route('roles.store'), [
            'name' => 'Fine Role',
            'permissions' => ['users.viewAny'],
        ])
        ->assertSessionHasNoErrors();

    expect(Role::where('name', 'Fine Role')->exists())->toBeTrue();
});

test('a Super Admin can grant any permission to a role', function (): void {
    actingAs(escSuperAdmin())
        ->post(route('roles.store'), [
            'name' => 'Powerful Role',
            'permissions' => ['system-settings.manageSecurity'],
        ])
        ->assertSessionHasNoErrors();

    expect(Role::findByName('Powerful Role', 'web')->hasPermissionTo('system-settings.manageSecurity'))->toBeTrue();
});

// ── E3: editing a Super Admin target ─────────────────────────────────────────

test('a delegated admin cannot edit a Super Admin account', function (): void {
    $superAdmin = escSuperAdmin();

    actingAs(escDelegatedAdmin())
        ->get(route('users.edit', $superAdmin))
        ->assertForbidden();
});

test('a delegated admin cannot update a Super Admin account', function (): void {
    $superAdmin = escSuperAdmin();

    actingAs(escDelegatedAdmin())
        ->patch(route('users.update', $superAdmin), [
            'name' => 'Hijacked',
            'email' => 'hijack@example.test',
        ])
        ->assertForbidden();

    expect($superAdmin->fresh()->email)->not->toBe('hijack@example.test');
});

test('a Super Admin can edit another Super Admin account', function (): void {
    $other = escSuperAdmin();

    actingAs(escSuperAdmin())
        ->get(route('users.edit', $other))
        ->assertOk();
});

// ── E4: granting a citywide organization scope ───────────────────────────────

test('a scoped delegated admin cannot grant a citywide scope', function (): void {
    $org = escMakeOrg();
    $actor = escDelegatedAdmin();

    // Constrain the actor to a single organization.
    UserOrganizationScope::query()->create([
        'user_id' => $actor->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    $target = User::factory()->create();

    actingAs($actor)
        ->post(route('users.organization-scopes.store', $target), [
            'scope_type' => 'citywide',
        ])
        ->assertSessionHasErrors('scope_type');

    expect($target->organizationScopes()->count())->toBe(0);
});

test('a Super Admin can grant a citywide scope', function (): void {
    $target = User::factory()->create();

    actingAs(escSuperAdmin())
        ->post(route('users.organization-scopes.store', $target), [
            'scope_type' => 'citywide',
        ])
        ->assertSessionHasNoErrors();

    expect($target->organizationScopes()->where('scope_type', 'citywide')->exists())->toBeTrue();
});
