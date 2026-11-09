<?php

declare(strict_types=1);

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
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
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Viewer', 'web')->givePermissionTo([
        'hierarchy-versions.viewAny',
        'hierarchy-versions.view',
    ]);

    Role::findOrCreate('Creator', 'web')->givePermissionTo([
        'hierarchy-versions.viewAny',
        'hierarchy-versions.view',
        'hierarchy-versions.create',
    ]);
});

test('user with hierarchy versions view permission can access index', function (): void {
    HierarchyVersion::query()->create([
        'version_name' => 'draft-v1',
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => now()->toDateString(),
    ]);

    $user = User::factory()->create();
    $user->assignRole('Viewer');

    $this->actingAs($user)
        ->get(route('hierarchy-versions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HierarchyVersions/Index')
            ->has('versions.data', 1)
            ->where('can.create', false)
        );
});

test('user without hierarchy versions view permission cannot access index', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('hierarchy-versions.index'))
        ->assertForbidden();
});

test('user with create permission receives create capability on index', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Creator');

    $this->actingAs($user)
        ->get(route('hierarchy-versions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.create', true));
});
