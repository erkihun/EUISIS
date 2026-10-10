<?php

declare(strict_types=1);

use App\Actions\Employees\RegisterEmployeeAction;
use App\Enums\AssignmentStatus;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\EmployeeStatus;
use App\Enums\EstablishmentStatus;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Employees/Create — position-driven prefill. Choosing a position must settle
 * the organization and unit, and the backend must reject any combination that
 * does not agree or falls outside the actor's scope.
 */
beforeEach(function (): void {
    foreach (['employees.view', 'employees.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('ECP Manager', 'web')->givePermissionTo(['employees.view', 'employees.manage']);

    CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::Employee->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Employee Number',
        'prefix' => 'ECP',
        'format' => '{PREFIX}-{SEQUENCE}',
        'separator' => '-',
        'sequence_length' => 6,
        'next_number' => 1,
        'initial_sequence_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Auto,
        'sequence_scope_tokens' => [],
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
    ]);

    $type = OrganizationType::query()->create([
        'code' => 'ECP-TYPE',
        'name_en' => 'Employee Create Prefill Type',
    ]);

    $this->orgA = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ECP-A',
        'name_en' => 'Prefill Organization A',
        'status' => 'active',
    ]);

    $this->orgB = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ECP-B',
        'name_en' => 'Prefill Organization B',
        'status' => 'active',
    ]);

    $this->unitA = OrganizationUnit::query()->create([
        'organization_id' => $this->orgA->id,
        'code' => 'ECP-A-U1',
        'name_en' => 'Prefill Unit A',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->unitB = OrganizationUnit::query()->create([
        'organization_id' => $this->orgB->id,
        'code' => 'ECP-B-U1',
        'name_en' => 'Prefill Unit B',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->positionA = Position::query()->create([
        'organization_id' => $this->orgA->id,
        'organization_unit_id' => $this->unitA->id,
        'job_position_code' => 'ECP-A-P1',
        'title_en' => 'Prefill Position A',
        'is_active' => true,
    ]);

    $this->positionB = Position::query()->create([
        'organization_id' => $this->orgB->id,
        'organization_unit_id' => $this->unitB->id,
        'job_position_code' => 'ECP-B-P1',
        'title_en' => 'Prefill Position B',
        'is_active' => true,
    ]);
});

function ecpManager(?Organization $scopeTo = null): User
{
    $user = User::factory()->create();
    $user->assignRole('ECP Manager');

    if ($scopeTo !== null) {
        $user->organizationScopes()->create([
            'organization_id' => $scopeTo->id,
            'scope_type' => 'self',
            'is_active' => true,
        ]);
    }

    return $user->fresh();
}

it('preselects organization, unit and position from query params', function (): void {
    $this->actingAs(ecpManager())
        ->get(route('employees.create', [
            'organization_id' => $this->orgA->id,
            'organization_unit_id' => $this->unitA->id,
            'position_id' => $this->positionA->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Create')
            ->where('selectedOrganizationId', $this->orgA->id)
            ->where('selectedOrganizationUnitId', $this->unitA->id)
            ->where('selectedPositionId', $this->positionA->id)
        );
});

it('derives organization and unit from a position_id given on its own', function (): void {
    $this->actingAs(ecpManager())
        ->get(route('employees.create', ['position_id' => $this->positionA->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The position alone settles where the employee lands.
            ->where('selectedOrganizationId', $this->orgA->id)
            ->where('selectedOrganizationUnitId', $this->unitA->id)
            ->where('selectedPositionId', $this->positionA->id)
        );
});

it('rejects a position that belongs to a different organization on store', function (): void {
    $this->actingAs(ecpManager())
        ->post(route('employees.store'), [
            'first_name' => 'Abebe',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $this->orgA->id,
            'position_id' => $this->positionB->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('position_id');
});

it('rejects a position that belongs to a different organization unit on store', function (): void {
    $otherUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->orgA->id,
        'code' => 'ECP-A-U2',
        'name_en' => 'Prefill Unit A2',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->actingAs(ecpManager())
        ->post(route('employees.store'), [
            'first_name' => 'Abebe',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $this->orgA->id,
            // Unit A2 does not host position A (which lives in unit A).
            'organization_unit_id' => $otherUnit->id,
            'position_id' => $this->positionA->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('position_id');
});

it('rejects an out-of-scope organization on store', function (): void {
    $scoped = ecpManager($this->orgA);

    $this->actingAs($scoped)
        ->post(route('employees.store'), [
            'first_name' => 'Abebe',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $this->orgB->id,
            'position_id' => $this->positionB->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('organization_id');
});

it('forbids opening the create page for an out-of-scope organization', function (): void {
    $scoped = ecpManager($this->orgA);

    $this->actingAs($scoped)
        ->get(route('employees.create', ['organization_id' => $this->orgB->id]))
        ->assertForbidden();
});

it('creates the employee when position, unit and organization agree', function (): void {
    $this->actingAs(ecpManager())
        ->post(route('employees.store'), [
            'first_name' => 'Abebe',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $this->orgA->id,
            'organization_unit_id' => $this->unitA->id,
            'position_id' => $this->positionA->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('employee_assignments', [
        'organization_id' => $this->orgA->id,
        'organization_unit_id' => $this->unitA->id,
        'position_id' => $this->positionA->id,
        'is_current' => true,
    ]);
});

it('exposes a resolved placement context for read-only display', function (): void {
    $this->actingAs(ecpManager())
        ->get(route('employees.create', ['position_id' => $this->positionA->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('placementContext.organization.id', $this->orgA->id)
            ->where('placementContext.organization.name_en', 'Prefill Organization A')
            ->where('placementContext.organization_unit.id', $this->unitA->id)
            ->where('placementContext.organization_unit.name_en', 'Prefill Unit A')
            ->where('placementContext.position.id', $this->positionA->id)
            ->where('placementContext.position.code', 'ECP-A-P1')
        );
});

it('omits the placement context when no position was chosen', function (): void {
    $this->actingAs(ecpManager())
        ->get(route('employees.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('placementContext', null));
});

it('redirects to the employee show page after a position-driven create', function (): void {
    $response = $this->actingAs(ecpManager())
        ->post(route('employees.store'), [
            'first_name' => 'Abebe',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $this->orgA->id,
            'organization_unit_id' => $this->unitA->id,
            'position_id' => $this->positionA->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->latest('created_at')->firstOrFail();
    $response->assertRedirect(route('employees.show', $employee));

    // The assignment must be active and current, not merely created.
    $this->assertDatabaseHas('employee_assignments', [
        'employee_id' => $employee->id,
        'position_id' => $this->positionA->id,
        'organization_unit_id' => $this->unitA->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
    ]);
});

it('blocks the create page for an occupied position with a flash message', function (): void {
    // Fill position A with a current, active assignment.
    $holder = Employee::query()->create([
        'employee_number' => 'ECP-HOLDER',
        'first_name' => 'Holder',
        'last_name' => 'Person',
        'full_name' => 'Holder Person',
        'status' => EmployeeStatus::Active->value,
    ]);

    EmployeeAssignment::query()->create([
        'employee_id' => $holder->id,
        'organization_id' => $this->orgA->id,
        'organization_unit_id' => $this->unitA->id,
        'position_id' => $this->positionA->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs(ecpManager())
        ->from(route('employees.index'))
        ->get(route('employees.create', ['position_id' => $this->positionA->id]))
        ->assertRedirect(route('employees.index'))
        ->assertSessionHas('flash.type', 'error');
});

it('flags the selected position as occupied on the employees index', function (): void {
    $holder = Employee::query()->create([
        'employee_number' => 'ECP-HOLDER-2',
        'first_name' => 'Holder',
        'last_name' => 'Two',
        'full_name' => 'Holder Two',
        'status' => EmployeeStatus::Active->value,
    ]);

    EmployeeAssignment::query()->create([
        'employee_id' => $holder->id,
        'organization_id' => $this->orgA->id,
        'organization_unit_id' => $this->unitA->id,
        'position_id' => $this->positionA->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs(ecpManager())
        ->get(route('employees.index', [
            'organization_id' => $this->orgA->id,
            'position_id' => $this->positionA->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedPosition.occupancy_status', 'occupied')
        );
});

it('keeps the create page reachable for a vacant position', function (): void {
    $this->actingAs(ecpManager())
        ->get(route('employees.create', ['position_id' => $this->positionA->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employees/Create'));
});

/*
 * Capacity rule (risk register CON-5, decision D-2): a position holds as many
 * people as its approved establishment slots effective today, and one person
 * when it has no approved establishment — the rule the structure import
 * already applied. Registration, the create page and the index agree on it.
 */
function ecpEstablish(Position $position, int $slots): void
{
    PositionEstablishment::query()->create([
        'establishment_number' => 'EST-ECP-'.uniqid(),
        'organization_id' => $position->organization_id,
        'position_id' => $position->id,
        'approved_slots' => $slots,
        'effective_from' => now()->subYear()->toDateString(),
        'status' => EstablishmentStatus::Approved->value,
    ]);
}

function ecpOccupy(Position $position, string $number): void
{
    $holder = Employee::query()->create([
        'employee_number' => $number, 'first_name' => 'Holder', 'last_name' => $number,
        'full_name' => 'Holder '.$number, 'status' => EmployeeStatus::Active->value,
    ]);
    EmployeeAssignment::query()->create([
        'employee_id' => $holder->id, 'organization_id' => $position->organization_id,
        'organization_unit_id' => $position->organization_unit_id, 'position_id' => $position->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);
}

it('registers up to the approved establishment slots and refuses the next person', function (): void {
    ecpEstablish($this->positionA, 2);
    ecpOccupy($this->positionA, 'ECP-CAP-1');
    $store = fn (string $first) => $this->actingAs(ecpManager())->post(route('employees.store'), [
        'first_name' => $first, 'last_name' => 'Capacity', 'status' => EmployeeStatus::Active->value,
        'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->unitA->id,
        'position_id' => $this->positionA->id, 'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs(ecpManager())->get(route('employees.create', ['position_id' => $this->positionA->id]))->assertOk();
    $store('Second')->assertSessionHasNoErrors();
    $store('Third')->assertSessionHasErrors('position_id');

    expect(EmployeeAssignment::query()->where('position_id', $this->positionA->id)->where('is_current', true)->count())->toBe(2);
});

it('shows a position with a free establishment slot as vacant', function (): void {
    ecpEstablish($this->positionA, 2);
    ecpOccupy($this->positionA, 'ECP-CAP-V');

    $this->actingAs(ecpManager())
        ->get(route('employees.index', ['organization_id' => $this->orgA->id, 'position_id' => $this->positionA->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selectedPosition.occupancy_status', 'vacant'));
});

it('refuses a second person in the locked registration path when there is no establishment', function (): void {
    ecpOccupy($this->positionA, 'ECP-CAP-L');

    expect(fn () => app(RegisterEmployeeAction::class)->execute(
        ['first_name' => 'Late', 'last_name' => 'Comer', 'full_name' => 'Late Comer', 'status' => EmployeeStatus::Active],
        ['organization_id' => $this->orgA->id, 'organization_unit_id' => $this->unitA->id, 'position_id' => $this->positionA->id, 'effective_from' => now()->toDateString()],
        ecpManager(),
    ))->toThrow(ValidationException::class);
});
