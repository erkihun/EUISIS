<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationScopeType;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Employee Create / Edit after the layout rework.
 *
 * The redesign touched markup only, so these tests assert the things the markup
 * could plausibly have broken: the pages still render with the props they need,
 * scope is still enforced on what the dropdowns may offer, validation still
 * round-trips, and create/update still write the record.
 */
beforeEach(function (): void {
    foreach (['employees.view', 'employees.viewAny', 'employees.manage'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('FL Manager', 'web')->givePermissionTo(['employees.view', 'employees.viewAny', 'employees.manage']);

    $this->flType = OrganizationType::query()->create([
        'code' => 'FL-TYPE',
        'name_en' => 'Form Layout Test Type',
    ]);

    CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::Employee->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Employee Number',
        'prefix' => 'FLE',
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
        'allow_manual_override' => true,
        'require_approval_for_override' => false,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Employee),
    ]);
});

function flManager(): User
{
    return tap(User::factory()->create())->assignRole('FL Manager');
}

function flOrg(string $code): Organization
{
    return Organization::query()->create([
        'organization_type_id' => test()->flType->id,
        'code' => $code,
        'name_en' => 'Org '.$code,
        'status' => 'active',
        'effective_from' => now()->toDateString(),
    ]);
}

function flUnit(Organization $org, string $code): OrganizationUnit
{
    return OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => $code,
        'name_en' => 'Unit '.$code,
        'status' => 'active',
    ]);
}

function flPosition(Organization $org, ?OrganizationUnit $unit, string $code): Position
{
    return Position::query()->create([
        'organization_id' => $org->id,
        'organization_unit_id' => $unit?->id,
        'job_position_code' => $code,
        'title_en' => 'Position '.$code,
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ]);
}

function flScopedTo(User $user, Organization $org): User
{
    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

function flEmployeeIn(Organization $org): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => 'FL-EMP-'.uniqid(),
        'first_name' => 'Meron',
        'last_name' => 'Alemu',
        'full_name' => 'Meron Alemu',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

// ── 1 & 2. Pages render with the props the new layout needs ───────────────

it('renders the create page with the option lists the form binds to', function (): void {
    $org = flOrg('FL-1');
    $unit = flUnit($org, 'FL-1-U1');
    flPosition($org, $unit, 'FL-1-P1');

    $this->actingAs(flManager())
        ->get(route('employees.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Create')
            ->has('organizations')
            ->has('organizationUnits')
            ->has('positions')
            ->has('hierarchyVersions')
            ->has('placementContext')
        );
});

it('renders the edit page with the employee payload the form binds to', function (): void {
    $org = flOrg('FL-2');
    $employee = flEmployeeIn($org);

    $this->actingAs(flManager())
        ->get(route('employees.edit', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Edit')
            ->where('employee.id', $employee->id)
            ->where('employee.employee_number', $employee->employee_number)
            ->has('employee.status')
            // The read-only placement panel reads from current_assignment.
            ->has('employee.current_assignment')
        );
});

// ── 4. Position context ───────────────────────────────────────────────────

it('sends a placement context when the create page is opened from a position', function (): void {
    $org = flOrg('FL-3');
    $unit = flUnit($org, 'FL-3-U1');
    $position = flPosition($org, $unit, 'FL-3-P1');

    $this->actingAs(flManager())
        ->get(route('employees.create', ['position_id' => $position->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Create')
            // Drives the read-only panel and the "Position Selected" badge.
            ->where('placementContext.position.id', $position->id)
            ->where('placementContext.organization.id', $org->id)
            ->where('placementContext.organization_unit.id', $unit->id)
            ->where('selectedPositionId', $position->id)
        );
});

it('sends no placement context when the create page is opened cold', function (): void {
    flOrg('FL-4');

    $this->actingAs(flManager())
        ->get(route('employees.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Create')
            // The page then renders the "select a vacant position" warning.
            ->where('placementContext', null)
            ->where('selectedPositionId', null)
        );
});

// ── 5. Scoped users ───────────────────────────────────────────────────────

it('offers a scoped user only in-scope organizations, units and positions', function (): void {
    $ownOrg = flOrg('FL-5-OWN');
    $ownUnit = flUnit($ownOrg, 'FL-5-OWN-U');
    flPosition($ownOrg, $ownUnit, 'FL-5-OWN-P');

    $otherOrg = flOrg('FL-5-OTHER');
    $otherUnit = flUnit($otherOrg, 'FL-5-OTHER-U');
    flPosition($otherOrg, $otherUnit, 'FL-5-OTHER-P');

    $scoped = flScopedTo(flManager(), $ownOrg);

    $this->actingAs($scoped)
        ->get(route('employees.create'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($ownOrg, $otherOrg): void {
            $organizationIds = collect($page->toArray()['props']['organizations'])->pluck('id');
            $unitOrganizationIds = collect($page->toArray()['props']['organizationUnits'])->pluck('organization_id');
            $positionOrganizationIds = collect($page->toArray()['props']['positions'])->pluck('organization_id');

            expect($organizationIds)->toContain($ownOrg->id)
                ->and($organizationIds)->not->toContain($otherOrg->id)
                ->and($unitOrganizationIds)->not->toContain($otherOrg->id)
                ->and($positionOrganizationIds)->not->toContain($otherOrg->id);
        });
});

it('refuses a scoped user opening the create page for an outside organization', function (): void {
    $ownOrg = flOrg('FL-6-OWN');
    $otherOrg = flOrg('FL-6-OTHER');

    $scoped = flScopedTo(flManager(), $ownOrg);

    $this->actingAs($scoped)
        ->get(route('employees.create', ['organization_id' => $otherOrg->id]))
        ->assertForbidden();
});

it('rejects a store that names an organization outside the user scope', function (): void {
    $ownOrg = flOrg('FL-7-OWN');
    $otherOrg = flOrg('FL-7-OTHER');

    $scoped = flScopedTo(flManager(), $ownOrg);

    $this->actingAs($scoped)
        ->post(route('employees.store'), [
            'first_name' => 'Abebe',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $otherOrg->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('organization_id');

    expect(Employee::query()->count())->toBe(0);
});

it('forbids a scoped user editing an employee outside their scope', function (): void {
    $ownOrg = flOrg('FL-8-OWN');
    $otherOrg = flOrg('FL-8-OTHER');

    $scoped = flScopedTo(flManager(), $ownOrg);
    $outsider = flEmployeeIn($otherOrg);

    $this->actingAs($scoped)->get(route('employees.edit', $outsider))->assertForbidden();
});

// ── 6. Validation round-trips into the form ───────────────────────────────

it('returns field errors and preserves input when the create form is invalid', function (): void {
    $org = flOrg('FL-9');

    $response = $this->actingAs(flManager())
        ->post(route('employees.store'), [
            'first_name' => '',
            'last_name' => '',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $org->id,
            'effective_from' => now()->toDateString(),
            'nationality' => 'Ethiopian',
        ]);

    $response->assertSessionHasErrors(['first_name', 'last_name']);

    // Inertia repopulates the form from the flashed old input, which is how the
    // redesigned form keeps typed values after a failed submit.
    expect(session()->getOldInput('nationality'))->toBe('Ethiopian');
});

// ── 7 & 8. Create and update still work ───────────────────────────────────

it('still creates an employee through the redesigned form', function (): void {
    $org = flOrg('FL-10');
    $unit = flUnit($org, 'FL-10-U1');
    $position = flPosition($org, $unit, 'FL-10-P1');

    $this->actingAs(flManager())
        ->post(route('employees.store'), [
            'first_name' => 'Abebe',
            'middle_name' => 'Kebede',
            'last_name' => 'Bekele',
            'gender' => 'male',
            'phone' => '0911000000',
            'email' => 'abebe@example.test',
            'address' => 'Bole Sub-city',
            'nationality' => 'Ethiopian',
            'emergency_contact_name' => 'Alemu Bekele',
            'emergency_contact_phone' => '+251911222333',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $org->id,
            'organization_unit_id' => $unit->id,
            'position_id' => $position->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect();

    $employee = Employee::query()->where('full_name', 'Abebe Kebede Bekele')->firstOrFail();
    $assignment = EmployeeAssignment::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($employee->nationality)->toBe('Ethiopian')
        ->and($employee->emergency_contact_name)->toBe('Alemu Bekele')
        ->and($assignment->position_id)->toBe($position->id)
        ->and($assignment->organization_id)->toBe($org->id)
        ->and($assignment->is_current)->toBeTrue();
});

it('still updates an employee through the redesigned form', function (): void {
    $org = flOrg('FL-11');
    $employee = flEmployeeIn($org);

    $this->actingAs(flManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Meron',
            'middle_name' => 'Tesfaye',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Suspended->value,
            'phone' => '0911444555',
            'address' => 'Yeka Sub-city',
            'nationality' => 'Ethiopian',
            'emergency_contact_name' => 'Dawit Haile',
            'emergency_contact_phone' => '0911666777',
        ])
        ->assertRedirect(route('employees.show', $employee));

    $employee->refresh();

    expect($employee->full_name)->toBe('Meron Tesfaye Alemu')
        ->and($employee->status)->toBe(EmployeeStatus::Suspended)
        ->and($employee->address)->toBe('Yeka Sub-city')
        ->and($employee->emergency_contact_phone)->toBe('0911666777');
});

it('keeps the employee placement untouched when the edit form is saved', function (): void {
    $org = flOrg('FL-12');
    $unit = flUnit($org, 'FL-12-U1');
    $position = flPosition($org, $unit, 'FL-12-P1');

    $employee = Employee::query()->create([
        'employee_number' => 'FL-12-EMP',
        'first_name' => 'Meron',
        'last_name' => 'Alemu',
        'full_name' => 'Meron Alemu',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    // The redesigned edit form posts no placement fields; even if one were
    // injected by hand, the update request does not accept it.
    $this->actingAs(flManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => flOrg('FL-12-OTHER')->id,
            'position_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        ])
        ->assertRedirect();

    $assignment->refresh();

    expect($assignment->organization_id)->toBe($org->id)
        ->and($assignment->position_id)->toBe($position->id)
        ->and(EmployeeAssignment::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
