<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('positions.viewAny', 'web');

    $type = OrganizationType::query()->create([
        'code' => 'POS-STATUS-FILTER',
        'name_en' => 'Position Status Filter Type',
    ]);

    $organization = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'FLT',
        'name_en' => 'Filter Organization',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'code' => 'FLT-UNIT',
        'name_en' => 'Filter Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    foreach (['FLT-1', 'FLT-2'] as $code) {
        Position::query()->create([
            'organization_id' => $organization->id,
            'organization_unit_id' => $unit->id,
            'job_position_code' => $code,
            'title_en' => $code,
            'is_active' => true,
        ]);
    }

    $role = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    $role->givePermissionTo('positions.viewAny');

    $user = User::factory()->create();
    $user->assignRole($role);
    $this->statusUser = $user->fresh();
});

it('ignores an organization filter that is not an id instead of failing', function (): void {
    $this->actingAs($this->statusUser)
        ->get(route('positions.status', ['organization_id' => 'x', 'organization_unit_id' => 'not-a-uuid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Positions/Status')
            ->where('summary.total_positions', 2)
            ->has('positions.data', 2));
});

it('still filters by a real organization id', function (): void {
    $organization = Organization::query()->where('code', 'FLT')->firstOrFail();

    $this->actingAs($this->statusUser)
        ->get(route('positions.status', ['organization_id' => $organization->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Positions/Status')
            ->where('summary.total_positions', 2)
            ->has('positions.data', 2));
});
