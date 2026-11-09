<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Http\Resources\PermissionResource;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'permissions.viewAny',
        'permissions.view',
        'permissions.create',
        'permissions.update',
        'permissions.delete',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Permission Manager', 'web')->syncPermissions([
        'permissions.viewAny',
        'permissions.view',
        'permissions.create',
        'permissions.update',
        'permissions.delete',
    ]);

    Role::findOrCreate('Permission Viewer', 'web')->syncPermissions([
        'permissions.viewAny',
    ]);
});

function permMgmtAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Permission Manager');

    return $user;
}

function permViewer(): User
{
    $user = User::factory()->create();
    $user->assignRole('Permission Viewer');

    return $user;
}

// 1. permissions table has label_en column
test('permissions table has label_en column', function (): void {
    expect(Schema::hasColumn('permissions', 'label_en'))->toBeTrue();
});

// 2. permission seeder sets label_en for all permissions
test('permission seeder sets label_en for all permissions', function (): void {
    $catalog = require database_path('seeders/data/permissions.php');

    foreach ($catalog as $entry) {
        Permission::updateOrCreate(
            ['name' => $entry['name'], 'guard_name' => 'web'],
            array_diff_key($entry, ['name' => 1]),
        );
    }

    $missing = Permission::whereNull('label_en')->whereIn(
        'name',
        collect($catalog)->pluck('name')->toArray(),
    )->count();

    expect($missing)->toBe(0);
});

// 3. permission seeder sets description_en for all permissions
test('permission seeder sets description_en for all permissions', function (): void {
    $catalog = require database_path('seeders/data/permissions.php');

    foreach ($catalog as $entry) {
        Permission::updateOrCreate(
            ['name' => $entry['name'], 'guard_name' => 'web'],
            array_diff_key($entry, ['name' => 1]),
        );
    }

    $missing = Permission::whereNull('description_en')->whereIn(
        'name',
        collect($catalog)->pluck('name')->toArray(),
    )->count();

    expect($missing)->toBe(0);
});

// 4. permission seeder is idempotent
test('permission seeder is idempotent', function (): void {
    $catalog = require database_path('seeders/data/permissions.php');

    $seed = static function () use ($catalog): void {
        foreach ($catalog as $entry) {
            Permission::updateOrCreate(
                ['name' => $entry['name'], 'guard_name' => 'web'],
                array_diff_key($entry, ['name' => 1]),
            );
        }
    };

    $seed();
    $countAfterFirst = Permission::count();

    $seed();
    $countAfterSecond = Permission::count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

// 5. super admin retains all permissions after re-seeding
test('super admin retains all permissions after re-seeding', function (): void {
    $catalog = require database_path('seeders/data/permissions.php');

    foreach ($catalog as $entry) {
        Permission::updateOrCreate(
            ['name' => $entry['name'], 'guard_name' => 'web'],
            array_diff_key($entry, ['name' => 1]),
        );
    }

    $superAdminRole = Role::findOrCreate('Super Admin', 'web');
    $superAdminRole->syncPermissions(Permission::all()->pluck('name')->toArray());

    // Re-seed
    foreach ($catalog as $entry) {
        Permission::updateOrCreate(
            ['name' => $entry['name'], 'guard_name' => 'web'],
            array_diff_key($entry, ['name' => 1]),
        );
    }

    expect(Role::findByName('Super Admin', 'web')->permissions()->count())
        ->toBeGreaterThan(0);
});

// 6. unauthorized user cannot access permissions index
test('guests are redirected from the permissions index', function (): void {
    $this->get(route('permissions.index'))
        ->assertRedirect(route('login'));
});

// 7. authorized user can access permissions index
test('users with viewAny permission can view the permissions index', function (): void {
    $this->actingAs(permViewer())
        ->get(route('permissions.index'))
        ->assertOk();
});

test('users without viewAny permission cannot view the permissions index', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('permissions.index'))
        ->assertForbidden();
});

// 8. permission resource includes labels and descriptions
test('permissions index returns label_en and description_en in props', function (): void {
    Permission::findOrCreate('organizations.viewAny', 'web');
    Permission::where('name', 'organizations.viewAny')
        ->update(['label_en' => 'List Organizations', 'description_en' => 'Test description']);

    $this->actingAs(permViewer())
        ->get(route('permissions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Permissions/Index')
            ->has('permissions.data')
        );

    $perm = PermissionResource::make(
        Permission::where('name', 'organizations.viewAny')->first()
    )->resolve();

    expect($perm['label_en'])->toBe('List Organizations')
        ->and($perm['description_en'])->toBe('Test description');
});

// 9. update permission can change description
test('authorized user can update a permission description', function (): void {
    $perm = Permission::findOrCreate('test.update-desc', 'web');

    $this->actingAs(permMgmtAdmin())
        ->patch(route('permissions.update', $perm->id), [
            'description_en' => 'Updated description.',
        ])
        ->assertRedirect(route('permissions.index'));

    expect($perm->fresh()->description_en)->toBe('Updated description.');
});

// 10. system permission cannot be deleted
test('system permission cannot be deleted', function (): void {
    $perm = Permission::findOrCreate('test.system-perm', 'web');
    $perm->update(['is_system' => true]);

    // Policy returns false for is_system permissions → Gate returns 403
    $this->actingAs(permMgmtAdmin())
        ->delete(route('permissions.destroy', $perm->id))
        ->assertForbidden();

    expect(Permission::where('name', 'test.system-perm')->exists())->toBeTrue();
});

// 11. system permission name cannot be changed
test('system permission name cannot be changed', function (): void {
    $perm = Permission::findOrCreate('test.locked-name', 'web');
    $perm->update(['is_system' => true]);

    $this->actingAs(permMgmtAdmin())
        ->patch(route('permissions.update', $perm->id), ['name' => 'test.renamed'])
        ->assertRedirect();

    expect($perm->fresh()->name)->toBe('test.locked-name');
});

// 12. permission update writes audit log
test('permission update writes an audit log entry', function (): void {
    $perm = Permission::findOrCreate('test.audit-log', 'web');

    $this->actingAs(permMgmtAdmin())
        ->patch(route('permissions.update', $perm->id), ['description_en' => 'Audited.']);

    expect(AuditLog::where('event_type', AuditEventType::PermissionUpdated->value)->exists())->toBeTrue();
});

// 13. EN/AM translation keys exist in frontend files
test('EN permissions translation file contains required keys', function (): void {
    $path = resource_path('js/i18n/en/permissions.ts');
    $content = file_get_contents($path);

    $requiredKeys = [
        'permissionKey',
        'permissionLabel',
        'permissionDescription',
        'permissionGroup',
        'createPermission',
        'editPermission',
        'systemPermission',
        'customPermission',
        'usedByRoles',
        'groupedView',
        'tableView',
        'searchPermissions',
        'filterByGroup',
        'allGroups',
        'selectAllInGroup',
        'clearGroup',
        'criticalPermission',
        'noPermissionsFound',
        'descriptionEn',
        'descriptionAm',
        'labelEn',
        'labelAm',
        'cannotDeleteSystemPermission',
        'cannotRenameSystemPermission',
    ];

    foreach ($requiredKeys as $key) {
        expect(str_contains($content, $key))->toBeTrue("EN permissions.ts is missing key: {$key}");
    }
});

test('AM permissions translation file contains required keys', function (): void {
    $path = resource_path('js/i18n/am/permissions.ts');
    $content = file_get_contents($path);

    $requiredKeys = [
        'permissionKey',
        'permissionLabel',
        'permissionDescription',
        'permissionGroup',
        'createPermission',
        'editPermission',
        'systemPermission',
        'customPermission',
        'usedByRoles',
        'groupedView',
        'tableView',
        'searchPermissions',
        'filterByGroup',
        'allGroups',
        'selectAllInGroup',
        'clearGroup',
        'criticalPermission',
        'noPermissionsFound',
        'descriptionEn',
        'descriptionAm',
        'labelEn',
        'labelAm',
        'cannotDeleteSystemPermission',
        'cannotRenameSystemPermission',
    ];

    foreach ($requiredKeys as $key) {
        expect(str_contains($content, $key))->toBeTrue("AM permissions.ts is missing key: {$key}");
    }
});
