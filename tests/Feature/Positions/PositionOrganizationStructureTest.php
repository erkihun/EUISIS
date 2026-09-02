<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\Positions\ScopedPositionStructureService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('positions.viewAny', 'web');

    $type = OrganizationType::query()->create([
        'code' => 'POS-STRUCT',
        'name_en' => 'Position Structure Type',
    ]);

    $this->orgInScope = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'POS-IN',
        'name_en' => 'In Scope Organization',
        'status' => 'active',
    ]);

    $this->orgOutOfScope = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'POS-OUT',
        'name_en' => 'Out Of Scope Organization',
        'status' => 'active',
    ]);

    foreach ([$this->orgInScope, $this->orgOutOfScope] as $organization) {
        $unit = OrganizationUnit::query()->create([
            'organization_id' => $organization->id,
            'code' => $organization->code.'-U1',
            'name_en' => $organization->code.' Unit',
            'unit_type' => 'department',
            'status' => 'active',
        ]);

        Position::query()->create([
            'organization_id' => $organization->id,
            'organization_unit_id' => $unit->id,
            'job_position_code' => $organization->code.'-P1',
            'title_en' => $organization->code.' Position',
            'is_active' => true,
        ]);
    }
});

function posStructureUser(string $roleName, ?Organization $scopeTo = null): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->givePermissionTo('positions.viewAny');

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

it('shows the organization structure section on the positions page', function (): void {
    $superAdmin = posStructureUser('Super Admin');

    $this->actingAs($superAdmin)
        ->get(route('positions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Positions/Index')
            ->has('organizationStructure')
            ->has('isOrganizationScoped')
        );
});

it('nests organization units and positions under each organization', function (): void {
    $superAdmin = posStructureUser('Super Admin');

    $this->actingAs($superAdmin)
        ->get(route('positions.index'))
        ->assertInertia(function (Assert $page): void {
            $structure = collect($page->toArray()['props']['organizationStructure']);
            $organization = $structure->firstWhere('code', 'POS-IN');

            expect($organization)->not->toBeNull()
                ->and($organization['units'])->not->toBeEmpty();

            $unit = $organization['units'][0];

            expect($unit['code'])->toBe('POS-IN-U1')
                ->and($unit)->toHaveKeys(['id', 'code', 'name_en', 'name_am', 'parent_unit_id', 'status', 'positions', 'children'])
                ->and($unit['positions'])->not->toBeEmpty();

            $position = $unit['positions'][0];

            expect($position)->toHaveKeys([
                'id', 'code', 'old_code', 'standard_name', 'bpr_name',
                'organization_unit_id', 'status', 'occupancy_status',
            ])->and($position['code'])->toBe('POS-IN-P1');
        });
});

it('limits the structure to the organizations an organizational admin is scoped to', function (): void {
    $scopedAdmin = posStructureUser('Organizational Admin', $this->orgInScope);

    $this->actingAs($scopedAdmin)
        ->get(route('positions.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $codes = collect($page->toArray()['props']['organizationStructure'])->pluck('code');

            expect($codes)->toContain('POS-IN')
                ->and($codes)->not->toContain('POS-OUT');

            expect($page->toArray()['props']['isOrganizationScoped'])->toBeTrue();
        });
});

it('never leaks out-of-scope units or positions into the tree', function (): void {
    $scopedAdmin = posStructureUser('Organizational Admin', $this->orgInScope);

    $this->actingAs($scopedAdmin)
        ->get(route('positions.index'))
        ->assertInertia(function (Assert $page): void {
            $json = json_encode($page->toArray()['props']['organizationStructure']);

            expect($json)->not->toContain('POS-OUT-U1')
                ->and($json)->not->toContain('POS-OUT-P1');
        });
});

it('returns an empty structure rather than every organization when the scope resolves empty', function (): void {
    // Scoped admin with no scope rows at all: must fail closed.
    $scopedAdmin = posStructureUser('Organizational Admin');

    $structure = app(ScopedPositionStructureService::class)->build($scopedAdmin);

    expect($structure)->toBe([]);
});

it('still shows every organization to an unrestricted super admin', function (): void {
    $superAdmin = posStructureUser('Super Admin');

    $this->actingAs($superAdmin)
        ->get(route('positions.index'))
        ->assertInertia(function (Assert $page): void {
            $codes = collect($page->toArray()['props']['organizationStructure'])->pluck('code');

            expect($codes)->toContain('POS-IN')
                ->and($codes)->toContain('POS-OUT');

            expect($page->toArray()['props']['isOrganizationScoped'])->toBeFalse();
        });
});

it('uses the multi-organization presentation for a restricted user with multiple accessible organizations', function (): void {
    $multiOrganizationUser = posStructureUser('HR Officer', $this->orgInScope);
    $multiOrganizationUser->organizationScopes()->create([
        'organization_id' => $this->orgOutOfScope->id,
        'scope_type' => 'self',
        'is_active' => true,
    ]);

    $this->actingAs($multiOrganizationUser->fresh())
        ->get(route('positions.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];
            $codes = collect($props['organizationStructure'])->pluck('code');

            expect($props['isOrganizationScoped'])->toBeFalse()
                ->and($codes)->toContain('POS-IN')
                ->and($codes)->toContain('POS-OUT');
        });
});

it('rejects a direct request for an organization outside the actor scope', function (): void {
    $scopedAdmin = posStructureUser('Organizational Admin', $this->orgInScope);

    $this->actingAs($scopedAdmin)
        ->get(route('positions.index', ['organization_id' => $this->orgOutOfScope->id]))
        ->assertForbidden();
});

it('builds the structure without an N+1 query explosion', function (): void {
    $superAdmin = posStructureUser('Super Admin');
    $service = app(ScopedPositionStructureService::class);

    DB::enableQueryLog();
    $service->build($superAdmin);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // organizations + units + positions + assignments, plus role lookups.
    expect($count)->toBeLessThanOrEqual(6);
});
