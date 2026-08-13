<?php

declare(strict_types=1);

use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    foreach (['positions.viewAny', 'positions.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $type = OrganizationType::query()->create([
        'code' => 'PSS-TYPE',
        'name_en' => 'Selection Type',
    ]);

    $this->orgA = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'PSS-A',
        'name_en' => 'Organization A',
        'status' => 'active',
    ]);

    $this->orgB = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'PSS-B',
        'name_en' => 'Organization B',
        'status' => 'active',
    ]);

    // Org A: two units, one position each.
    $this->unitOne = OrganizationUnit::query()->create([
        'organization_id' => $this->orgA->id,
        'code' => 'PSS-A-U1',
        'name_en' => 'Unit One',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->unitTwo = OrganizationUnit::query()->create([
        'organization_id' => $this->orgA->id,
        'code' => 'PSS-A-U2',
        'name_en' => 'Unit Two',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->positionOne = Position::query()->create([
        'organization_id' => $this->orgA->id,
        'organization_unit_id' => $this->unitOne->id,
        'job_position_code' => 'PSS-A-P1',
        'title_en' => 'Position One',
        'is_active' => true,
    ]);

    $this->positionTwo = Position::query()->create([
        'organization_id' => $this->orgA->id,
        'organization_unit_id' => $this->unitTwo->id,
        'job_position_code' => 'PSS-A-P2',
        'title_en' => 'Position Two',
        'is_active' => true,
    ]);

    // Org B: out of scope for the scoped admin.
    $this->unitB = OrganizationUnit::query()->create([
        'organization_id' => $this->orgB->id,
        'code' => 'PSS-B-U1',
        'name_en' => 'Unit B',
        'unit_type' => 'department',
        'status' => 'active',
    ]);
});

function pssUser(string $roleName, array $permissions, ?Organization $scopeTo = null): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    if ($scopeTo !== null) {
        $user->organizationScopes()->create([
            'organization_id' => $scopeTo->id,
            'scope_type' => 'self',
            'is_active' => true,
        ]);
    }

    return $user->fresh();
}

it('shows every position in the organization when an organization is selected', function (): void {
    $admin = pssUser('Super Admin', ['positions.viewAny', 'positions.create']);

    $this->actingAs($admin)
        ->get(route('positions.index', ['organization_id' => $this->orgA->id]))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $codes = collect($page->toArray()['props']['positions'])->pluck('job_position_code');

            // Both units' positions appear when only the organization is chosen.
            expect($codes)->toContain('PSS-A-P1')
                ->and($codes)->toContain('PSS-A-P2');
        });
});

it('shows only that unit positions when an organization unit is selected', function (): void {
    $admin = pssUser('Super Admin', ['positions.viewAny', 'positions.create']);

    $this->actingAs($admin)
        ->get(route('positions.index', [
            'organization_id' => $this->orgA->id,
            'organization_unit_id' => $this->unitOne->id,
        ]))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $codes = collect($page->toArray()['props']['positions'])->pluck('job_position_code');

            expect($codes)->toContain('PSS-A-P1')
                ->and($codes)->not->toContain('PSS-A-P2');

            expect($page->toArray()['props']['selectedUnit']['id'])->toBe($this->unitOne->id);
        });
});

it('preselects the organization on the create page', function (): void {
    $admin = pssUser('Super Admin', ['positions.viewAny', 'positions.create']);

    $this->actingAs($admin)
        ->get(route('positions.create', ['organization_id' => $this->orgA->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedOrganizationId', $this->orgA->id)
        );
});

it('preselects both organization and unit on the create page', function (): void {
    $admin = pssUser('Super Admin', ['positions.viewAny', 'positions.create']);

    $this->actingAs($admin)
        ->get(route('positions.create', [
            'organization_id' => $this->orgA->id,
            'organization_unit_id' => $this->unitOne->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedOrganizationId', $this->orgA->id)
            ->where('selectedOrganizationUnitId', $this->unitOne->id)
        );
});

it('rejects an out-of-scope organization on the index', function (): void {
    $scoped = pssUser('Organizational Admin', ['positions.viewAny', 'positions.create'], $this->orgA);

    $this->actingAs($scoped)
        ->get(route('positions.index', ['organization_id' => $this->orgB->id]))
        ->assertForbidden();
});

it('rejects an out-of-scope organization on the create page', function (): void {
    $scoped = pssUser('Organizational Admin', ['positions.viewAny', 'positions.create'], $this->orgA);

    $this->actingAs($scoped)
        ->get(route('positions.create', ['organization_id' => $this->orgB->id]))
        ->assertForbidden();
});

it('rejects a create prefill whose unit belongs to another organization', function (): void {
    $admin = pssUser('Super Admin', ['positions.viewAny', 'positions.create']);

    // orgA paired with a unit that actually belongs to orgB.
    $this->actingAs($admin)
        ->get(route('positions.create', [
            'organization_id' => $this->orgA->id,
            'organization_unit_id' => $this->unitB->id,
        ]))
        ->assertForbidden();
});

it('rejects storing a position whose unit belongs to another organization', function (): void {
    $admin = pssUser('Super Admin', ['positions.viewAny', 'positions.create']);
    $occupation = Occupation::query()->create([
        'code' => 'PSS-OCC',
        'isco_code' => '2513',
        'name_en' => 'Selection Occupation',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->post(route('positions.store'), [
            'title_en' => 'Mismatched Position',
            'organization_id' => $this->orgA->id,
            'organization_unit_id' => $this->unitB->id,
            'occupation_id' => $occupation->id,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('organization_unit_id');
});
