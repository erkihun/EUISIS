<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use Spatie\Permission\Models\Permission as SpatiePermission;

function permissionsPageViewer(array $extra = []): User
{
    foreach (array_merge(['permissions.viewAny', 'permissions.view'], $extra) as $name) {
        SpatiePermission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo(array_merge(['permissions.viewAny', 'permissions.view'], $extra));

    return $user;
}

test('permissions index renders with pagination, stats, and groups', function (): void {
    $user = permissionsPageViewer();

    Permission::query()->updateOrCreate(
        ['name' => 'employees.viewAny', 'guard_name' => 'web'],
        ['group' => 'employees', 'label_en' => 'View Employees', 'is_system' => true],
    );

    $response = $this->actingAs($user)->get('/permissions');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Permissions/Index')
        ->has('permissions.data')
        ->has('permissions.meta.current_page')
        ->has('permissions.meta.total')
        ->has('stats.total')
        ->has('stats.groups')
        ->has('stats.system')
        ->has('groups')
        ->has('groupCounts')
        ->has('guards')
        ->has('filters.search')
    );
});

test('permissions are grouped by module with group counts', function (): void {
    $user = permissionsPageViewer();

    Permission::query()->updateOrCreate(
        ['name' => 'employees.viewAny', 'guard_name' => 'web'],
        ['group' => 'employees', 'label_en' => 'View Employees'],
    );
    Permission::query()->updateOrCreate(
        ['name' => 'organizations.viewAny', 'guard_name' => 'web'],
        ['group' => 'organizations', 'label_en' => 'View Organizations'],
    );

    $response = $this->actingAs($user)->get('/permissions');

    $response->assertInertia(fn ($page) => $page
        ->where('groupCounts', fn ($counts) => collect($counts)->has('employees') && collect($counts)->has('organizations'))
    );
});

test('permissions filter by module and by the scope of the roles holding them', function (): void {
    $user = permissionsPageViewer();

    Permission::query()->create(['name' => 'employees.scopedProbe', 'guard_name' => 'web', 'group' => 'employees']);
    Permission::query()->create(['name' => 'employees.globalProbe', 'guard_name' => 'web', 'group' => 'employees']);
    Permission::query()->create(['name' => 'organizations.probe', 'guard_name' => 'web', 'group' => 'organizations']);
    Role::create(['name' => 'Scoped HR', 'guard_name' => 'web', 'scope_type' => 'scoped'])->givePermissionTo('employees.scopedProbe');
    Role::create(['name' => 'City HR', 'guard_name' => 'web', 'scope_type' => 'global'])->givePermissionTo('employees.globalProbe');

    $this->actingAs($user)
        ->get('/permissions?module=employee-management')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.module', 'employee-management')
            ->where('modules', fn ($modules) => collect($modules)->firstWhere('key', 'employee-management')['scopes']['scoped'] >= 1)
            ->where('permissions.data', fn ($rows) => collect($rows)->pluck('group')->diff(PermissionCatalog::CATEGORIES['Employee Management'])->isEmpty()
                && collect($rows)->doesntContain('name', 'organizations.probe')
                && collect($rows)->firstWhere('name', 'employees.scopedProbe')['scope'] === 'scoped'
                && collect($rows)->firstWhere('name', 'employees.globalProbe')['scope'] === 'global')
        );

    $this->actingAs($user)
        ->get('/permissions?module=employee-management&scope=global')
        ->assertInertia(fn ($page) => $page
            ->where('filters.scope', 'global')
            ->where('permissions.data', fn ($rows) => collect($rows)->contains('name', 'employees.globalProbe')
                && collect($rows)->doesntContain('name', 'employees.scopedProbe'))
        );
});

test('search and filters do not break the page', function (): void {
    $user = permissionsPageViewer();

    Permission::query()->updateOrCreate(
        ['name' => 'employees.viewAny', 'guard_name' => 'web'],
        ['group' => 'employees', 'label_en' => 'View Employees'],
    );

    $this->actingAs($user)
        ->get('/permissions?search=employees&group=employees&guard=web&page=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.search', 'employees')
            ->where('filters.group', 'employees')
            ->where('permissions.data', fn ($rows) => collect($rows)->pluck('name')->contains('employees.viewAny'))
        );
});

test('system permission rows expose delete as forbidden', function (): void {
    $user = permissionsPageViewer(['permissions.update', 'permissions.delete']);

    Permission::query()->updateOrCreate(
        ['name' => 'employees.viewAny', 'guard_name' => 'web'],
        ['group' => 'employees', 'is_system' => true],
    );
    Permission::query()->updateOrCreate(
        ['name' => 'custom.report', 'guard_name' => 'web'],
        ['group' => 'custom', 'is_system' => false],
    );

    $response = $this->actingAs($user)->get('/permissions?search=employees.viewAny');
    $response->assertInertia(fn ($page) => $page
        ->where('permissions.data', function ($rows) {
            $row = collect($rows)->firstWhere('name', 'employees.viewAny');

            return $row !== null && $row['can']['delete'] === false && $row['can']['update'] === true;
        })
    );

    $response = $this->actingAs($user)->get('/permissions?search=custom.report');
    $response->assertInertia(fn ($page) => $page
        ->where('permissions.data', fn ($rows) => collect($rows)->firstWhere('name', 'custom.report')['can']['delete'] === true)
    );
});

test('system permission cannot be deleted even with direct request', function (): void {
    $user = permissionsPageViewer(['permissions.delete']);

    $protected = Permission::query()->updateOrCreate(
        ['name' => 'employees.viewAny', 'guard_name' => 'web'],
        ['group' => 'employees', 'is_system' => true],
    );

    $this->actingAs($user)
        ->delete(route('permissions.destroy', $protected))
        ->assertForbidden();

    $this->assertDatabaseHas('permissions', ['name' => 'employees.viewAny']);
});

test('unauthorized user cannot access permission management', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/permissions')->assertForbidden();
    $this->actingAs($user)->get('/permissions/create')->assertForbidden();
});

test('authorized user can view a single permission page', function (): void {
    $user = permissionsPageViewer();

    $permission = Permission::query()->updateOrCreate(
        ['name' => 'employees.viewAny', 'guard_name' => 'web'],
        ['group' => 'employees', 'label_en' => 'View Employees'],
    );

    $this->actingAs($user)
        ->get(route('permissions.show', $permission))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Permissions/Show')
            ->where('permission.name', 'employees.viewAny')
            ->has('permission.created_at')
            ->has('permission.can')
        );
});
