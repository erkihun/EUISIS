<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'roles.viewAny',
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'roles.assignPermissions',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Role Manager', 'web')->syncPermissions([
        'roles.viewAny',
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'roles.assignPermissions',
    ]);

    Role::findOrCreate('Role Viewer', 'web')->syncPermissions([
        'roles.viewAny',
    ]);
});

function roleMgmtAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Role Manager');

    return $user;
}

function roleMgmtViewer(): User
{
    $user = User::factory()->create();
    $user->assignRole('Role Viewer');

    return $user;
}

function makeTestRole(string $name = 'Custom Role'): Role
{
    return Role::findOrCreate($name, 'web');
}

// ── Index ──────────────────────────────────────────────────────────────────

test('guests are redirected from the roles index', function (): void {
    $this->get(route('roles.index'))
        ->assertRedirect(route('login'));
});

test('users with viewAny permission can view the roles index', function (): void {
    $this->actingAs(roleMgmtViewer())
        ->get(route('roles.index'))
        ->assertOk();
});

test('users without viewAny permission cannot view the roles index', function (): void {
    $noRoleUser = User::factory()->create();

    $this->actingAs($noRoleUser)
        ->get(route('roles.index'))
        ->assertForbidden();
});

// ── Create page ────────────────────────────────────────────────────────────

test('users without create permission cannot access the create page', function (): void {
    $this->actingAs(roleMgmtViewer())
        ->get(route('roles.create'))
        ->assertForbidden();
});

test('users with create permission can access the create page', function (): void {
    $this->actingAs(roleMgmtAdmin())
        ->get(route('roles.create'))
        ->assertOk();
});

// ── Store ──────────────────────────────────────────────────────────────────

test('users without create permission cannot store a role', function (): void {
    $this->actingAs(roleMgmtViewer())
        ->post(route('roles.store'), ['name' => 'New Role'])
        ->assertForbidden();
});

test('users with create permission can store a role', function (): void {
    $this->actingAs(roleMgmtAdmin())
        ->post(route('roles.store'), ['name' => 'Auditor', 'permissions' => []])
        ->assertRedirect(route('roles.index'));

    $this->assertDatabaseHas('roles', ['name' => 'Auditor']);
});

// ── Edit page ──────────────────────────────────────────────────────────────

test('users without update permission cannot access the edit page', function (): void {
    $role = makeTestRole();

    $this->actingAs(roleMgmtViewer())
        ->get(route('roles.edit', $role))
        ->assertForbidden();
});

test('users with update permission can access the edit page', function (): void {
    $role = makeTestRole();

    $this->actingAs(roleMgmtAdmin())
        ->get(route('roles.edit', $role))
        ->assertOk();
});

// ── Update ─────────────────────────────────────────────────────────────────

test('users without update permission cannot update a role', function (): void {
    $role = makeTestRole();

    $this->actingAs(roleMgmtViewer())
        ->patch(route('roles.update', $role), [
            'name' => 'Renamed Role',
            'permissions' => [],
        ])
        ->assertForbidden();
});

test('users with update permission can update a role', function (): void {
    $role = makeTestRole('Old Name');

    $this->actingAs(roleMgmtAdmin())
        ->patch(route('roles.update', $role), [
            'name' => 'New Name',
            'permissions' => [],
        ])
        ->assertRedirect(route('roles.index'));

    expect($role->fresh()->name)->toBe('New Name');
});

test('non-super-admin users cannot edit the Super Admin role', function (): void {
    $superAdminRole = Role::findOrCreate('Super Admin', 'web');

    $this->actingAs(roleMgmtAdmin())
        ->get(route('roles.edit', $superAdminRole))
        ->assertForbidden();
});

// ── Destroy ────────────────────────────────────────────────────────────────

test('users without delete permission cannot delete a role', function (): void {
    $role = makeTestRole();

    $this->actingAs(roleMgmtViewer())
        ->delete(route('roles.destroy', $role))
        ->assertForbidden();
});

test('users with delete permission can delete a role', function (): void {
    $role = makeTestRole('To Delete');

    $this->actingAs(roleMgmtAdmin())
        ->delete(route('roles.destroy', $role))
        ->assertRedirect(route('roles.index'));

    $this->assertDatabaseMissing('roles', ['name' => 'To Delete']);
});

test('non-super-admin users cannot delete the Super Admin role', function (): void {
    $superAdminRole = Role::findOrCreate('Super Admin', 'web');

    $this->actingAs(roleMgmtAdmin())
        ->delete(route('roles.destroy', $superAdminRole))
        ->assertForbidden();
});

test('the roles index filters by search and scope and paginates like every other list', function (): void {
    makeTestRole('Alpha Reviewer')->update(['scope_type' => 'global']);
    makeTestRole('Beta Clerk')->update(['scope_type' => 'scoped']);

    $this->actingAs(roleMgmtViewer())
        ->get(route('roles.index', ['search' => 'alpha']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Roles/Index')
            ->has('roles.data', 1)
            ->where('roles.data.0.name', 'Alpha Reviewer')
            ->where('filters.search', 'alpha')
        );

    $this->actingAs(roleMgmtViewer())
        ->get(route('roles.index', ['scope' => 'scoped', 'search' => 'clerk']))
        ->assertInertia(fn ($page) => $page
            ->has('roles.data', 1)
            ->where('roles.data.0.name', 'Beta Clerk')
        );

    $this->actingAs(roleMgmtViewer())
        ->get(route('roles.index'))
        ->assertInertia(fn ($page) => $page->has('roles.total'));
});
