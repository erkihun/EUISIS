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
        'code' => 'POS-STATUS-SCOPE',
        'name_en' => 'Position Status Scope Type',
    ]);

    $this->statusOrganizationInScope = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'STATUS-IN',
        'name_en' => 'Status In Scope Organization',
        'status' => 'active',
    ]);

    $this->statusOrganizationOutOfScope = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'STATUS-OUT',
        'name_en' => 'Status Out Of Scope Organization',
        'status' => 'active',
    ]);

    foreach ([$this->statusOrganizationInScope, $this->statusOrganizationOutOfScope] as $organization) {
        $unit = OrganizationUnit::query()->create([
            'organization_id' => $organization->id,
            'code' => $organization->code.'-UNIT',
            'name_en' => $organization->code.' Unit',
            'unit_type' => 'department',
            'status' => 'active',
        ]);

        Position::query()->create([
            'organization_id' => $organization->id,
            'organization_unit_id' => $unit->id,
            'job_position_code' => $organization->code.'-POSITION',
            'title_en' => $organization->code.' Position',
            'is_active' => true,
        ]);
    }
});

function positionStatusScopeUser(string $roleName, ?Organization $organization = null): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->givePermissionTo('positions.viewAny');

    $user = User::factory()->create();
    $user->assignRole($role);

    if ($organization !== null) {
        $user->organizationScopes()->create([
            'organization_id' => $organization->id,
            'scope_type' => 'self',
            'is_active' => true,
        ]);
    }

    return $user->fresh();
}

it('marks the position status page as scoped and returns only in-scope data', function (): void {
    $scopedUser = positionStatusScopeUser('Organizational Admin', $this->statusOrganizationInScope);

    $this->actingAs($scopedUser)
        ->get(route('positions.status'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];
            $positionCodes = collect($props['positions']['data'])->pluck('job_position_code');
            $organizationIds = collect($props['organizations'])->pluck('id');

            expect($props['isOrganizationScoped'])->toBeTrue()
                ->and($positionCodes)->toContain('STATUS-IN-POSITION')
                ->and($positionCodes)->not->toContain('STATUS-OUT-POSITION')
                ->and($organizationIds)->toContain($this->statusOrganizationInScope->id)
                ->and($organizationIds)->not->toContain($this->statusOrganizationOutOfScope->id);
        });
});

it('keeps the position status page global for an unrestricted administrator', function (): void {
    $globalUser = positionStatusScopeUser('Super Admin');

    $this->actingAs($globalUser)
        ->get(route('positions.status'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];
            $positionCodes = collect($props['positions']['data'])->pluck('job_position_code');
            $organizationIds = collect($props['organizations'])->pluck('id');

            expect($props['isOrganizationScoped'])->toBeFalse()
                ->and($positionCodes)->toContain('STATUS-IN-POSITION')
                ->and($positionCodes)->toContain('STATUS-OUT-POSITION')
                ->and($organizationIds)->toContain($this->statusOrganizationInScope->id)
                ->and($organizationIds)->toContain($this->statusOrganizationOutOfScope->id);
        });
});

it('does not return outside-scope status data when a scoped user tampers with the organization filter', function (): void {
    $scopedUser = positionStatusScopeUser('Organizational Admin', $this->statusOrganizationInScope);

    $this->actingAs($scopedUser)
        ->get(route('positions.status', ['organization_id' => $this->statusOrganizationOutOfScope->id]))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];

            expect($props['positions']['data'])->toBeEmpty()
                ->and($props['summary']['total_positions'])->toBe(0);
        });
});

it('fails closed when an organizational admin has no resolvable organization scope', function (): void {
    $scopedUser = positionStatusScopeUser('Organizational Admin');

    $this->actingAs($scopedUser)
        ->get(route('positions.status'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];

            expect($props['isOrganizationScoped'])->toBeTrue()
                ->and($props['positions']['data'])->toBeEmpty()
                ->and($props['organizations'])->toBeEmpty()
                ->and($props['organizationUnits'])->toBeEmpty()
                ->and($props['summary']['total_positions'])->toBe(0);
        });
});
