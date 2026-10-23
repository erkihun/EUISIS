<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\Core\CoreDashboardMetrics;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/*
 * The Core dashboard's figures: real counts of master records, inside the
 * viewer's organization scope, showing only what the viewer's permissions
 * cover.
 */

const CORE_FIGURE_PERMISSIONS = [
    'organizations.view', 'organization-units.viewAny', 'hierarchy-versions.viewAny', 'positions.viewAny',
    'employees.view', 'cards.view', 'id-cards.viewAny', 'users.viewAny', 'api_management.view', 'integrations.view', 'audit.view',
];

beforeEach(function (): void {
    $this->type = OrganizationType::query()->create(['code' => 'CORE-T', 'name_en' => 'Core type']);
});

function coreViewer(array $permissions): User
{
    foreach (['core.access', ...$permissions] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['core.access', ...$permissions]);

    return $user;
}

/** An active organization with one unit, one active and one inactive position, and $staff placed employees with active cards. */
function coreOrganization(OrganizationType $type, string $code, int $staff): Organization
{
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => $code, 'name_en' => 'Org '.$code, 'status' => 'active']);
    $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'code' => $code.'-U', 'name_en' => 'Unit '.$code, 'unit_type' => 'department', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => $code.'-P', 'title_en' => 'Officer', 'is_active' => true]);
    Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => $code.'-P2', 'title_en' => 'Retired post', 'is_active' => false]);

    for ($i = 0; $i < $staff; $i++) {
        $employee = Employee::query()->create(['employee_number' => $code.'-E'.$i, 'first_name' => 'Core', 'last_name' => 'Staff', 'full_name' => 'Core Staff', 'status' => EmployeeStatus::Active->value]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id,
            'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->subYear()->toDateString(),
        ]);
        $employee->forceFill(['current_assignment_id' => $assignment->id])->save();
        IdCard::query()->create([
            'employee_id' => $employee->id, 'card_number' => $code.'-C'.$i, 'status' => CardStatus::Active->value, 'is_current' => true,
            // The first card of each organization runs out within the expiry window.
            'expires_at' => $i === 0 ? now()->addDays(10) : now()->addYears(2),
        ]);
        CardRequest::query()->create(['employee_id' => $employee->id, 'requested_by' => User::factory()->create()->id, 'request_type' => CardRequestType::New, 'status' => CardRequestStatus::Submitted]);
    }

    return $organization;
}

/** @return array<string, int|string|null> figure key => value, from the dashboard payload */
function coreFigures(array $metrics): array
{
    return collect($metrics['domains'])->flatMap(fn (array $domain) => collect($domain['metrics'])->pluck('value', 'key'))->all();
}

it('counts the master records a citywide viewer is responsible for', function (): void {
    $viewer = coreViewer(CORE_FIGURE_PERMISSIONS);
    $metrics = app(CoreDashboardMetrics::class);
    $before = coreFigures($metrics->for($viewer));

    coreOrganization($this->type, 'CORE-A', 3);
    coreOrganization($this->type, 'CORE-B', 2);
    // An active employee with no placement shows up as needing attention.
    Employee::query()->create(['employee_number' => 'CORE-LOOSE', 'first_name' => 'No', 'last_name' => 'Placement', 'full_name' => 'No Placement', 'status' => EmployeeStatus::Active->value]);
    Cache::flush();

    $after = $metrics->for($viewer);
    $delta = fn (string $key): int => (int) coreFigures($after)[$key] - (int) ($before[$key] ?? 0);

    expect($after['citywide'])->toBeTrue()
        ->and(collect($after['domains'])->pluck('key')->all())->toBe(['organization', 'positions', 'employees', 'credentials', 'governance'])
        ->and($delta('organizations_active'))->toBe(2)
        ->and($delta('units_active'))->toBe(2)
        ->and($delta('positions_active'))->toBe(2)
        ->and($delta('positions_inactive'))->toBe(2)
        ->and($delta('employees_active'))->toBe(6)
        ->and($delta('assignments_current'))->toBe(5)
        ->and($delta('employees_unplaced'))->toBe(1)
        ->and($delta('cards_active'))->toBe(5)
        ->and($delta('cards_expiring'))->toBe(2)
        ->and($delta('card_requests_open'))->toBe(5);
});

it('limits a scoped viewer to their organizations and leaves citywide figures out', function (): void {
    $mine = coreOrganization($this->type, 'CORE-MINE', 2);
    coreOrganization($this->type, 'CORE-OTHER', 3);
    $viewer = coreViewer(CORE_FIGURE_PERMISSIONS);
    $viewer->organizationScopes()->create(['organization_id' => $mine->id, 'scope_type' => 'self', 'is_active' => true]);

    $metrics = app(CoreDashboardMetrics::class)->for($viewer);
    $figures = coreFigures($metrics);

    expect($metrics['citywide'])->toBeFalse()
        ->and($metrics['organization_count'])->toBe(1)
        ->and($figures['organizations_active'])->toBe(1)
        ->and($figures['units_active'])->toBe(1)
        ->and($figures['positions_active'])->toBe(1)
        ->and($figures['employees_active'])->toBe(2)
        ->and($figures['assignments_current'])->toBe(2)
        ->and($figures['cards_active'])->toBe(2)
        ->and($figures['cards_expiring'])->toBe(1)
        ->and($figures['card_requests_open'])->toBe(2)
        ->and($figures)->not->toHaveKeys(['employees_unplaced', 'applications_active', 'integration_pending', 'audit_events_24h']);
});

it('shows only the figures the viewer\'s permissions cover', function (): void {
    coreOrganization($this->type, 'CORE-C', 1);
    $viewer = coreViewer(['cards.view']);

    $metrics = app(CoreDashboardMetrics::class)->for($viewer);

    expect(collect($metrics['domains'])->pluck('key')->all())->toBe(['credentials'])
        ->and(array_keys(coreFigures($metrics)))->toBe(['cards_active', 'cards_expiring'])
        ->and($metrics['domains'][0]['href'])->toBe(route('id-cards.index'));
});

it('renders the dashboard with the viewer\'s figures', function (): void {
    coreOrganization($this->type, 'CORE-D', 1);
    $viewer = coreViewer(['employees.view']);

    $this->actingAs($viewer)->get(route('core.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Core/Dashboard')
            ->where('metrics.domains.0.key', 'employees')
            ->where('metrics.domains.0.href', route('employees.index'))
            ->has('metrics.domains.0.metrics', 3));
});

it('keeps a scoped viewer\'s figures apart from a citywide viewer\'s in the cache', function (): void {
    $mine = coreOrganization($this->type, 'CORE-S1', 1);
    coreOrganization($this->type, 'CORE-S2', 2);
    $citywide = coreViewer(['employees.view']);
    $scoped = coreViewer(['employees.view']);
    $scoped->organizationScopes()->create(['organization_id' => $mine->id, 'scope_type' => 'self', 'is_active' => true]);

    $all = coreFigures(app(CoreDashboardMetrics::class)->for($citywide));
    $own = coreFigures(app(CoreDashboardMetrics::class)->for($scoped));

    expect($own['employees_active'])->toBe(1)->and($all['employees_active'])->toBeGreaterThanOrEqual(3);
});

it('never counts a soft-deleted or inactive organization', function (): void {
    $viewer = coreViewer(['organizations.view']);
    $before = coreFigures(app(CoreDashboardMetrics::class)->for($viewer))['organizations_active'];
    Organization::query()->create(['organization_type_id' => $this->type->id, 'code' => 'CORE-OFF', 'name_en' => 'Inactive', 'status' => 'inactive']);
    Organization::query()->create(['organization_type_id' => $this->type->id, 'code' => 'CORE-GONE', 'name_en' => 'Removed', 'status' => 'active'])->delete();
    Cache::flush();

    expect(coreFigures(app(CoreDashboardMetrics::class)->for($viewer))['organizations_active'])->toBe($before);
});
