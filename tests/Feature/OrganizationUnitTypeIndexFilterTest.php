<?php

declare(strict_types=1);

use App\Models\OrganizationUnitType;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Permission::findOrCreate('organization-unit-types.viewAny', 'web');
    Role::findOrCreate('Unit Type Viewer', 'web')->syncPermissions(['organization-unit-types.viewAny']);
});

function unitTypeIndexViewer(): User
{
    $user = User::factory()->create();
    $user->assignRole('Unit Type Viewer');

    return $user;
}

it('filters the unit types index by search and status and paginates like every other list', function (): void {
    OrganizationUnitType::query()->create(['code' => 'DIR', 'name_en' => 'Directorate', 'is_active' => true]);
    OrganizationUnitType::query()->create(['code' => 'TEAM', 'name_en' => 'Team', 'is_active' => false]);
    OrganizationUnitType::query()->create(['code' => 'OLD', 'name_en' => 'Old Desk', 'is_active' => true])->delete();

    $this->actingAs(unitTypeIndexViewer())
        ->get(route('organization-unit-types.index', ['search' => 'direct']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrganizationUnitTypes/Index')
            ->has('types.data', 1)
            ->where('types.data.0.code', 'DIR')
            ->where('filters.search', 'direct')
        );

    // "Archived" covers both soft-deleted types and ones switched off.
    $this->actingAs(unitTypeIndexViewer())
        ->get(route('organization-unit-types.index', ['status' => 'archived']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('types.data', 2));

    $this->actingAs(unitTypeIndexViewer())
        ->get(route('organization-unit-types.index', ['status' => 'active']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('types.data', 1)
            ->where('types.data.0.code', 'DIR')
        );

    $this->actingAs(unitTypeIndexViewer())
        ->get(route('organization-unit-types.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('types.total', 3));
});
