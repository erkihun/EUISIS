<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\Organizations\OrganizationStatisticsService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.viewAny'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('OS Viewer', 'web')->givePermissionTo(['organizations.view', 'organizations.viewAny']);

    $type = OrganizationType::query()->create(['code' => 'OS-TYPE', 'name_en' => 'Stats Type']);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OS-A',
        'name_en' => 'Stats Organization',
        'status' => 'active',
    ]);

    $this->otherOrg = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OS-B',
        'name_en' => 'Other Organization',
        'status' => 'active',
    ]);

    // Two units: one active, one inactive.
    $this->activeUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'OS-A-U1',
        'name_en' => 'Active Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'OS-A-U2',
        'name_en' => 'Inactive Unit',
        'unit_type' => 'department',
        'status' => 'inactive',
    ]);

    // Two positions: one occupied, one vacant.
    $this->occupiedPosition = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->activeUnit->id,
        'job_position_code' => 'OS-A-P1',
        'title_en' => 'Occupied Position',
        'is_active' => true,
    ]);

    Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->activeUnit->id,
        'job_position_code' => 'OS-A-P2',
        'title_en' => 'Vacant Position',
        'is_active' => true,
    ]);

    $this->employee = Employee::query()->create([
        'employee_number' => 'OS-EMP-1',
        'first_name' => 'Abebe',
        'last_name' => 'Bekele',
        'full_name' => 'Abebe Bekele',
        'status' => EmployeeStatus::Active->value,
    ]);

    EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->activeUnit->id,
        'position_id' => $this->occupiedPosition->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    IdCard::query()->create([
        'employee_id' => $this->employee->id,
        'card_number' => 'OS-CARD-1',
        'status' => CardStatus::Active->value,
        'is_current' => true,
    ]);
});

function osViewer(?Organization $scopeTo = null): User
{
    $user = User::factory()->create();
    $user->assignRole('OS Viewer');

    if ($scopeTo !== null) {
        $user->organizationScopes()->create([
            'organization_id' => $scopeTo->id,
            'scope_type' => 'self',
            'is_active' => true,
        ]);
    }

    return $user->fresh();
}

it('computes unit, position, employee and card statistics correctly', function (): void {
    $stats = app(OrganizationStatisticsService::class)->forOrganization($this->org);

    expect($stats['units']['total'])->toBe(2)
        ->and($stats['units']['active'])->toBe(1)
        ->and($stats['units']['inactive'])->toBe(1)
        ->and($stats['positions']['total'])->toBe(2)
        ->and($stats['positions']['occupied'])->toBe(1)
        ->and($stats['positions']['vacant'])->toBe(1)
        ->and($stats['employees']['total'])->toBe(1)
        ->and($stats['employees']['active'])->toBe(1)
        ->and($stats['id_cards']['total'])->toBe(1)
        ->and($stats['id_cards']['active'])->toBe(1);
});

it('counts employees per organization unit', function (): void {
    $stats = app(OrganizationStatisticsService::class)->forOrganization($this->org);

    expect($stats['employees_by_unit'])->toHaveCount(1)
        ->and($stats['employees_by_unit'][0]['code'])->toBe('OS-A-U1')
        ->and($stats['employees_by_unit'][0]['employees_count'])->toBe(1);
});

it('never counts records belonging to another organization', function (): void {
    $stats = app(OrganizationStatisticsService::class)->forOrganization($this->otherOrg);

    expect($stats['units']['total'])->toBe(0)
        ->and($stats['positions']['total'])->toBe(0)
        ->and($stats['employees']['total'])->toBe(0)
        ->and($stats['id_cards']['total'])->toBe(0)
        ->and($stats['employees_by_unit'])->toBe([]);
});

it('renders the statistics payload on the organization show page', function (): void {
    $this->actingAs(osViewer())
        ->get(route('organizations.show', $this->org))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Show')
            ->where('statistics.units.total', 2)
            ->where('statistics.positions.occupied', 1)
            ->where('statistics.employees.total', 1)
            ->has('statistics.id_cards')
            ->has('statistics.employees_by_unit')
        );
});

it('forbids a scoped admin from viewing an out-of-scope organization', function (): void {
    $scoped = osViewer($this->org);

    $this->actingAs($scoped)
        ->get(route('organizations.show', $this->otherOrg))
        ->assertForbidden();
});

it('builds statistics with a flat query count regardless of structure size', function (): void {
    // Add more units/positions; the query count must not grow with them.
    for ($i = 0; $i < 6; $i++) {
        $unit = OrganizationUnit::query()->create([
            'organization_id' => $this->org->id,
            'code' => "OS-A-EX{$i}",
            'name_en' => "Extra Unit {$i}",
            'unit_type' => 'department',
            'status' => 'active',
        ]);

        Position::query()->create([
            'organization_id' => $this->org->id,
            'organization_unit_id' => $unit->id,
            'job_position_code' => "OS-A-EP{$i}",
            'title_en' => "Extra Position {$i}",
            'is_active' => true,
        ]);
    }

    $service = app(OrganizationStatisticsService::class);

    DB::enableQueryLog();
    $service->forOrganization($this->org);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual(10);
});

it('does not expose employee names in the statistics payload', function (): void {
    $stats = app(OrganizationStatisticsService::class)->forOrganization($this->org);

    // Only aggregates — no employee-identifying fields anywhere in the tree.
    expect(json_encode($stats))->not->toContain('Abebe')
        ->and(json_encode($stats))->not->toContain('OS-EMP-1');
});

it('exposes by_status series for the position, employee and card charts', function (): void {
    $stats = app(OrganizationStatisticsService::class)->forOrganization($this->org);

    expect($stats['positions']['by_status'])->toBe(['occupied' => 1, 'vacant' => 1])
        ->and($stats['employees']['by_status'])->toBe(['active' => 1])
        ->and($stats['id_cards']['by_status'])->toBe(['active' => 1]);
});

it('ships the chart series to the show page', function (): void {
    $this->actingAs(osViewer())
        ->get(route('organizations.show', $this->org))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.positions.by_status.occupied', 1)
            ->where('statistics.positions.by_status.vacant', 1)
            ->has('statistics.employees.by_status')
            ->has('statistics.id_cards.by_status')
        );
});
