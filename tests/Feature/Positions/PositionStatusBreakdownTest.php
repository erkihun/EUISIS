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
        'code' => 'POS-STATUS-BREAKDOWN',
        'name_en' => 'Position Status Breakdown Type',
    ]);

    $this->breakdownOrganization = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'BRK',
        'name_en' => 'Breakdown Organization',
        'name_am' => 'Breakdown Organization AM',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $this->breakdownOrganization->id,
        'code' => 'BRK-UNIT',
        'name_en' => 'Breakdown Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    foreach ([['BRK-1', 'X', 'Finance'], ['BRK-2', 'X', 'Finance'], ['BRK-3', 'Y', null]] as [$code, $grade, $family]) {
        Position::query()->create([
            'organization_id' => $this->breakdownOrganization->id,
            'organization_unit_id' => $unit->id,
            'job_position_code' => $code,
            'title_en' => $code,
            'grade_level' => $grade,
            'job_family' => $family,
            'is_active' => true,
        ]);
    }
});

function positionStatusBreakdownUser(): User
{
    $role = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    $role->givePermissionTo('positions.viewAny');

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

it('returns occupancy breakdowns that reconcile with the summary tiles', function (): void {
    $this->actingAs(positionStatusBreakdownUser())
        ->get(route('positions.status'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];
            $summary = $props['summary'];
            $breakdowns = $props['breakdowns'];

            expect($summary)->toHaveKeys(['total_positions', 'filled_positions', 'vacant_positions', 'occupancy_rate'])
                ->and($breakdowns['grouping'])->toBe('organization')
                ->and($breakdowns)->toHaveKeys(['by_group', 'by_grade_level', 'by_job_family']);

            // Every breakdown slices the same population, so each one's totals
            // must add back up to the headline numbers.
            foreach (['by_group', 'by_grade_level', 'by_job_family'] as $key) {
                expect(collect($breakdowns[$key])->sum('total'))->toBe($summary['total_positions'])
                    ->and(collect($breakdowns[$key])->sum('filled'))->toBe($summary['filled_positions'])
                    ->and(collect($breakdowns[$key])->sum('vacant'))->toBe($summary['vacant_positions']);
            }

            $grades = collect($breakdowns['by_grade_level'])->pluck('total', 'key');
            expect($grades['X'])->toBe(2)->and($grades['Y'])->toBe(1);

            // A position with no job family still has to appear, under the
            // unassigned bucket, or the chart would silently drop it.
            expect(collect($breakdowns['by_job_family'])->pluck('key'))->toContain('__unassigned__');
        });
});

it('groups the status breakdown by unit for an organization-scoped user', function (): void {
    $role = Role::query()->firstOrCreate(['name' => 'Organizational Admin', 'guard_name' => 'web']);
    $role->givePermissionTo('positions.viewAny');

    $user = User::factory()->create();
    $user->assignRole($role);
    $user->organizationScopes()->create([
        'organization_id' => $this->breakdownOrganization->id,
        'scope_type' => 'self',
        'is_active' => true,
    ]);

    $this->actingAs($user->fresh())
        ->get(route('positions.status'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $breakdowns = $page->toArray()['props']['breakdowns'];

            expect($breakdowns['grouping'])->toBe('unit')
                ->and(collect($breakdowns['by_group'])->pluck('label_en'))->toContain('Breakdown Unit');
        });
});
