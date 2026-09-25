<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionUsageScanner;
use App\Support\Rbac\RbacSynchronizer;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
 * RBAC seeding (docs/rbac-architecture.md): one catalog, one matrix,
 * idempotent seeders that never delete, never touch custom roles and never
 * change user assignments. Numbers refer to the audit brief (61, 71).
 */

function rbacCounts(): array
{
    return [
        'permissions' => Permission::query()->count(),
        'roles' => Role::query()->count(),
        'grants' => DB::table('role_has_permissions')->count(),
        'assignments' => DB::table('model_has_roles')->count(),
    ];
}

function rbacSeedAll(): void
{
    test()->seed(PermissionSeeder::class);
    test()->seed(RoleSeeder::class);
    test()->seed(RolePermissionSeeder::class);
}

// ── Catalog and matrix validity (4–8, 53, 54) ────────────────────────────────

test('4 7 the permission catalog is valid: unique dot-notation names, a categorized group, bilingual labels and descriptions', function (): void {
    expect(PermissionCatalog::problems())->toBe([])
        ->and(count(PermissionCatalog::names()))->toBe(count(array_unique(PermissionCatalog::names())));
});

test('54 duplicate permission names are rejected by validation', function (): void {
    $entry = ['name' => 'dup.check', 'group' => 'users', 'label_en' => 'x', 'label_am' => 'x', 'description_en' => 'x', 'description_am' => 'x'];

    expect(PermissionCatalog::problems([$entry, $entry]))->toContain('dup.check: duplicate name');
});

test('5 6 53 the default role matrix is valid: every permission exists, no duplicates, known scopes, bilingual descriptions', function (): void {
    expect(DefaultRoleMatrix::problems())->toBe([])
        ->and(count(DefaultRoleMatrix::names()))->toBe(count(array_unique(DefaultRoleMatrix::names())));
});

test('8 every catalog permission and default role uses the web guard', function (): void {
    rbacSeedAll();

    expect(PermissionCatalog::GUARD)->toBe('web')
        ->and(Permission::query()->whereIn('name', PermissionCatalog::names())->where('guard_name', '!=', 'web')->count())->toBe(0)
        ->and(Role::query()->whereIn('name', DefaultRoleMatrix::names())->where('guard_name', '!=', 'web')->count())->toBe(0);
});

// ── Idempotency (1–3) ────────────────────────────────────────────────────────

test('1 2 3 permission, role and role-permission seeders are idempotent', function (): void {
    rbacSeedAll();
    $first = rbacCounts();

    rbacSeedAll();
    rbacSeedAll();

    expect(rbacCounts())->toBe($first)
        ->and(Permission::query()->count())->toBe(Permission::query()->distinct()->count('name'))
        ->and(app(RbacSynchronizer::class)->syncCatalog())->toBe(['created' => 0, 'updated' => 0])
        ->and(app(RbacSynchronizer::class)->applyMatrix())->toBe([]);

    foreach (DefaultRoleMatrix::roles() as $name => $definition) {
        $role = Role::findByName($name, 'web');
        expect($role->isSystem())->toBeTrue()
            ->and($role->description_en)->toBe($definition['description_en'])
            ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(collect($definition['permissions'])->sort()->values()->all());
    }
});

test('reseeding keeps custom roles, legacy roles, stale permissions and user assignments', function (): void {
    rbacSeedAll();
    $stale = Permission::query()->create(['name' => 'legacy.module_action', 'guard_name' => 'web']);
    $custom = Role::query()->create(['name' => 'Bole Records Clerk', 'guard_name' => 'web']);
    $custom->givePermissionTo(['employees.view', 'legacy.module_action']);
    $hr = User::factory()->create();
    $hr->assignRole('HR Officer');

    rbacSeedAll();

    expect(Permission::query()->whereKey($stale->id)->exists())->toBeTrue()
        ->and($custom->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['employees.view', 'legacy.module_action'])
        ->and($custom->fresh()->isSystem())->toBeFalse()
        ->and($hr->fresh()->hasRole('HR Officer'))->toBeTrue();
});

test('sync mode restores a system role to the matrix; additive mode only adds', function (): void {
    rbacSeedAll();
    $role = Role::findByName('Report Viewer', 'web');
    $role->givePermissionTo('users.viewAny');
    $role->revokePermissionTo('reports.view');

    $additive = app(RbacSynchronizer::class)->applyMatrix(RbacSynchronizer::MODE_ADDITIVE);
    expect($additive['Report Viewer'])->toBe(['added' => ['reports.view'], 'removed' => []])
        ->and($role->fresh()->hasPermissionTo('users.viewAny'))->toBeTrue();

    $sync = app(RbacSynchronizer::class)->applyMatrix(RbacSynchronizer::MODE_SYNC);
    expect($sync['Report Viewer']['removed'])->toBe(['users.viewAny'])
        ->and($role->fresh()->hasPermissionTo('users.viewAny'))->toBeFalse();
});

test('the migration path is additive: existing default roles gain missing permissions and lose none', function (): void {
    $role = Role::query()->firstOrCreate(['name' => 'Auditor', 'guard_name' => 'web']);
    Permission::findOrCreate('users.viewAny', 'web');
    $role->syncPermissions(['users.viewAny']);

    $rbac = app(RbacSynchronizer::class);
    $rbac->syncCatalog();
    $rbac->ensureRoles(createMissing: false);
    $rbac->applyMatrix(RbacSynchronizer::MODE_ADDITIVE);

    expect($role->fresh()->hasPermissionTo('users.viewAny'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('audit-logs.viewAny'))->toBeTrue()
        ->and($role->fresh()->isSystem())->toBeTrue()
        ->and(Role::query()->where('name', 'ID Card Approver')->exists())->toBeFalse();
});

// ── Discovery (52, 55) ───────────────────────────────────────────────────────

test('52 every permission the code checks exists in the catalog', function (): void {
    expect((new PermissionUsageScanner)->missingFromCatalog())->toBe([]);
});

test('55 stale permission candidates are reported, not removed', function (): void {
    rbacSeedAll();
    $stale = (new PermissionUsageScanner)->unusedCatalogEntries();

    expect($stale)->not->toBeEmpty()
        ->and(Permission::query()->whereIn('name', $stale)->count())->toBe(count($stale));

    $this->artisan('rbac:report', ['--json' => true])->assertSuccessful();
});

test('system roles cannot be deleted; custom roles can', function (): void {
    rbacSeedAll();
    $security = User::factory()->create();
    $security->assignRole('Security Settings Manager');
    $custom = Role::query()->create(['name' => 'Temporary Helpdesk', 'guard_name' => 'web']);

    expect($security->can('delete', Role::findByName('Report Viewer', 'web')))->toBeFalse()
        ->and($security->can('delete', $custom))->toBeTrue();
});
