<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CourtStaffMember as User;
use Database\Seeders\Court\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function performancePermissionUser(array $permissions = [], string $roleName = 'performance-test-role'): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $roleId = DB::table('court_roles')->insertGetId([
        'name' => $roleName,
        'description' => 'Performance permission test role',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('court_role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($permissions as $permission) {
        $permissionId = DB::table('court_permissions')->where('name', $permission)->value('id');
        DB::table('court_permission_role')->insert([
            'permission_id' => $permissionId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $user;
}

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

test('performance evaluation routes declare independent backend permissions', function (): void {
    $expected = [
        'court.performance-evaluations.index' => 'court.perm:performance-evaluations.view',
        'court.performance-evaluations.show' => 'court.perm:performance-evaluations.view',
        'court.performance-evaluations.create' => 'court.perm:performance-evaluations.create',
        'court.performance-evaluations.store' => 'court.perm:performance-evaluations.create',
        'court.performance-evaluations.edit' => 'court.perm:performance-evaluations.update',
        'court.performance-evaluations.update' => 'court.perm:performance-evaluations.update',
        'court.performance-evaluations.destroy' => 'court.perm:performance-evaluations.delete',
        'court.performance-evaluations.review' => 'court.perm:performance-evaluations.review',
    ];

    foreach ($expected as $routeName => $middleware) {
        expect(Route::getRoutes()->getByName($routeName)?->gatherMiddleware())
            ->toContain($middleware);
    }
});

test('user without view permission cannot access the module by direct url', function (): void {
    $user = performancePermissionUser();

    $this->actingAs($user)
        ->get(route('court.performance-evaluations.index'))
        ->assertRedirect(route('court.dashboard'))
        ->assertSessionHas('error');
});

test('view permission grants read access but not create access', function (): void {
    $user = performancePermissionUser(['performance-evaluations.view']);

    $this->actingAs($user)
        ->get(route('court.performance-evaluations.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Performance/Index')
            ->where('can.create', false)
            ->where('can.update', false));

    $this->actingAs($user)
        ->get(route('court.performance-evaluations.create'))
        ->assertRedirect(route('court.dashboard'));
});

test('admin role retains the global permission bypass', function (): void {
    $admin = performancePermissionUser([], 'admin');

    $this->actingAs($admin)
        ->get(route('court.performance-evaluations.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Performance/Index')
            ->where('can.create', true));
});
