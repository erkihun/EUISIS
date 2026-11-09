<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\DisabilityType;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\MaritalStatus;
use App\Enums\OrganizationScopeType;
use App\Models\CodeRule;
use App\Models\EducationLevel;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Date of birth, gender and the statistical fields of an employee: what the
 * forms accept, what they refuse, who sees a declared disability, and the
 * hierarchy version a new placement is recorded against.
 */

beforeEach(function (): void {
    foreach (['employees.view', 'employees.viewAny', 'employees.manage', 'employees.viewPii'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Stats Manager', 'web')->givePermissionTo(['employees.view', 'employees.viewAny', 'employees.manage']);

    $this->esType = OrganizationType::query()->create(['code' => 'ES-TYPE', 'name_en' => 'Statistics Type']);
    $this->esOrg = Organization::query()->create([
        'organization_type_id' => $this->esType->id, 'code' => 'ES-ORG', 'name_en' => 'Statistics Org',
        'name_am' => 'የስታቲስቲክስ ተቋም', 'status' => 'active', 'effective_from' => now()->toDateString(),
    ]);
    $this->bachelor = EducationLevel::query()->create(['code' => 'ES-BACHELOR', 'name_en' => 'Bachelor degree', 'name_am' => 'የመጀመሪያ ዲግሪ', 'rank' => 60]);

    CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::Employee->value, 'scope_type' => null, 'scope_id' => null,
        'name_en' => 'Employee Number', 'prefix' => 'ESE', 'format' => '{PREFIX}-{SEQUENCE}', 'separator' => '-',
        'sequence_length' => 6, 'next_number' => 1, 'initial_sequence_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Auto, 'sequence_scope_tokens' => [],
        'reset_frequency' => CodeRuleResetFrequency::Never, 'year_format' => 'Y', 'is_active' => true,
        'allow_manual_override' => true, 'require_approval_for_override' => false,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Employee),
    ]);
});

function esManager(bool $sensitive = false): User
{
    $user = tap(User::factory()->create())->assignRole('Stats Manager');
    if ($sensitive) {
        $user->givePermissionTo('employees.viewPii');
    }
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'scope_type' => OrganizationScopeType::Citywide, 'organization_id' => null, 'is_active' => true,
    ]);

    return $user;
}

function esPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Hiwot',
        'last_name' => 'Tadesse',
        'status' => EmployeeStatus::Active->value,
        'organization_id' => test()->esOrg->id,
        'effective_from' => now()->toDateString(),
        'date_of_birth' => '1988-04-12',
        'gender' => 'female',
        'marital_status' => 'married',
        'education_level_id' => test()->bachelor->id,
        'field_of_study' => 'Accounting',
        'has_disability' => true,
        'disability_type' => 'visual',
        'service_start_date' => '2012-09-11',
    ], $overrides);
}

function esPlaced(Organization $org, array $overrides = []): Employee
{
    $employee = Employee::query()->create(array_merge([
        'employee_number' => 'ES-EMP-'.uniqid(), 'first_name' => 'Dawit', 'last_name' => 'Kebede',
        'full_name' => 'Dawit Kebede', 'status' => EmployeeStatus::Active->value,
    ], $overrides));
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org->id,
        'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

it('saves the statistical fields of a new employee', function (): void {
    $this->actingAs(esManager())
        ->post(route('employees.store'), esPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $employee = Employee::query()->where('full_name', 'Hiwot Tadesse')->sole();

    expect($employee->date_of_birth->toDateString())->toBe('1988-04-12')
        ->and($employee->marital_status)->toBe(MaritalStatus::Married)
        ->and($employee->education_level_id)->toBe($this->bachelor->id)
        ->and($employee->field_of_study)->toBe('Accounting')
        ->and($employee->has_disability)->toBeTrue()
        ->and($employee->disability_type)->toBe(DisabilityType::Visual)
        ->and($employee->service_start_date->toDateString())->toBe('2012-09-11');
});

it('refuses a future birth date, an unknown gender and a disability without its type', function (): void {
    $this->actingAs(esManager())
        ->post(route('employees.store'), esPayload([
            'date_of_birth' => now()->addDay()->toDateString(),
            'gender' => 'unknown',
            'disability_type' => '',
        ]))
        ->assertSessionHasErrors(['date_of_birth', 'gender', 'disability_type']);
});

it('refuses a public service start before the birth date', function (): void {
    $this->actingAs(esManager())
        ->post(route('employees.store'), esPayload(['service_start_date' => '1980-01-01']))
        ->assertSessionHasErrors('service_start_date');
});

it('clears the disability type when no disability is declared', function (): void {
    $this->actingAs(esManager())
        ->post(route('employees.store'), esPayload(['has_disability' => false, 'disability_type' => 'visual']))
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->where('full_name', 'Hiwot Tadesse')->sole();
    expect($employee->has_disability)->toBeFalse()
        ->and($employee->disability_type)->toBeNull();
});

it('records the placement against the published hierarchy, not a form choice', function (): void {
    $draft = HierarchyVersion::query()->create(['version_name' => 'Draft 2027', 'status' => HierarchyVersionStatus::Draft->value, 'effective_from' => now()->addYear()->toDateString()]);
    $published = HierarchyVersion::query()->create(['version_name' => 'Published', 'status' => HierarchyVersionStatus::Published->value, 'effective_from' => now()->subYear()->toDateString()]);

    $this->actingAs(esManager())
        ->post(route('employees.store'), esPayload(['hierarchy_version_id' => $draft->id]))
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->where('full_name', 'Hiwot Tadesse')->sole();
    expect($employee->currentAssignment->hierarchy_version_id)->toBe($published->id);
});

it('hides a declared disability from a user who may not see sensitive data', function (): void {
    $employee = esPlaced($this->esOrg, ['has_disability' => true, 'disability_type' => 'hearing']);

    $this->actingAs(esManager())
        ->get(route('employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.can_view_sensitive', false)
            ->where('employee.has_disability', null)
            ->where('employee.disability_type', null));

    $this->actingAs(esManager(sensitive: true))
        ->get(route('employees.show', $employee))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.has_disability', true)
            ->where('employee.disability_type', 'hearing'));
});

it('keeps a declared disability when an editor without sensitive access saves the record', function (): void {
    $employee = esPlaced($this->esOrg, ['has_disability' => true, 'disability_type' => 'hearing']);

    // The edit form leaves the disability fields out for such a user.
    $this->actingAs(esManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Dawit', 'last_name' => 'Kebede', 'status' => EmployeeStatus::Active->value,
            'marital_status' => 'single',
        ])
        ->assertSessionHasNoErrors();

    $employee->refresh();
    expect($employee->marital_status)->toBe(MaritalStatus::Single)
        ->and($employee->has_disability)->toBeTrue()
        ->and($employee->disability_type)->toBe(DisabilityType::Hearing);
});

it('offers the education levels on the create form and no hierarchy versions', function (): void {
    $this->actingAs(esManager())
        ->get(route('employees.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Create')
            ->has('educationLevels', 1)
            ->missing('hierarchyVersions'));
});
