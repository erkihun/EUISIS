<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformancePlan;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\EmployeeAgreementService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'AGR-T', 'name_en' => 'Agreement test']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AGR-A', 'name_en' => 'Organization A', 'status' => 'active']);
    $this->otherOrganization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AGR-B', 'name_en' => 'Organization B', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->organization->id, 'code' => 'AGR-U', 'name_en' => 'Unit A', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->otherUnit = OrganizationUnit::query()->create(['organization_id' => $this->organization->id, 'code' => 'AGR-V', 'name_en' => 'Unit B', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'AGR-P', 'title_en' => 'Officer', 'is_active' => true]);
    $this->employee = Employee::query()->create(['employee_number' => 'AGR-E', 'first_name' => 'Agreement', 'last_name' => 'Employee', 'full_name' => 'Agreement Employee', 'status' => 'active']);
    $this->assignment = EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
    $this->employee->update(['current_assignment_id' => $this->assignment->id]);
    $this->cycle = PerformanceCycle::query()->create(['code' => 'AGR-2026', 'name_en' => 'Agreement cycle', 'organization_id' => $this->organization->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->cycle->forceFill(['status' => 'ACTIVE'])->save();
    $this->actor = User::factory()->create();
    foreach (['employee_performance_agreements.manage', 'performance_plans.approve'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    UserOrganizationScope::query()->create(['user_id' => $this->actor->id, 'organization_id' => $this->organization->id, 'scope_type' => 'self', 'is_active' => true]);
});

function assignmentContextPlan(array $overrides = [], int $version = 1): PerformancePlan
{
    $t = test();
    $plan = new PerformancePlan([
        'cycle_id' => $t->cycle->id, 'organization_id' => $t->organization->id,
        'organization_unit_id' => $t->unit->id, 'position_id' => $t->position->id,
        'plan_type' => 'POSITION', 'title' => 'Assignment plan', ...$overrides,
    ]);
    $plan->forceFill(['status' => 'PUBLISHED', 'version_no' => $version, 'lineage_key' => (string) Str::uuid()])->save();

    return $plan;
}

test('agreements reject inactive, noncurrent and nonprimary assignments', function (string $case): void {
    if ($case === 'inactive') {
        $this->assignment->update(['assignment_status' => 'closed']);
    } elseif ($case === 'noncurrent') {
        $this->assignment->update(['is_current' => false]);
    } else {
        $this->employee->update(['current_assignment_id' => null]);
    }

    expect(fn () => app(EmployeeAgreementService::class)->create($this->employee, $this->assignment, $this->cycle, $this->actor))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('employee_performance_agreements', 0);
})->with(['inactive', 'noncurrent', 'nonprimary']);

test('agreements select the latest published target plan in the exact assignment context and period', function (): void {
    assignmentContextPlan(['organization_id' => $this->otherOrganization->id], 99);
    assignmentContextPlan(['organization_unit_id' => $this->otherUnit->id], 98);
    assignmentContextPlan(['effective_from' => '2027-01-01'], 97);
    assignmentContextPlan(['effective_to' => '2025-12-31'], 96);
    assignmentContextPlan([], 1);
    $latest = assignmentContextPlan(['effective_from' => '2026-07-01'], 2);
    $draft = assignmentContextPlan([], 3);
    $draft->forceFill(['status' => 'DRAFT'])->save();

    $agreement = app(EmployeeAgreementService::class)->create($this->employee, $this->assignment, $this->cycle, $this->actor);

    expect($agreement->performance_plan_id)->toBe($latest->id)
        ->and($agreement->organization_id)->toBe($this->assignment->organization_id)
        ->and($agreement->organization_unit_id)->toBe($this->assignment->organization_unit_id);
});

test('an unrelated published plan cannot supply an agreement and acting agreements retain their primary assignment', function (): void {
    assignmentContextPlan(['organization_unit_id' => $this->otherUnit->id]);
    $agreement = app(EmployeeAgreementService::class)->create($this->employee, $this->assignment, $this->cycle, $this->actor);
    expect($agreement->performance_plan_id)->toBeNull();

    $temporaryAssignment = EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->organization->id, 'organization_unit_id' => $this->otherUnit->id, 'assignment_status' => 'active', 'effective_from' => '2026-07-01', 'is_current' => false]);
    $temporary = app(EmployeeAgreementService::class)->create($this->employee, $temporaryAssignment, $this->cycle, $this->actor, temporary: true);

    expect($temporary->is_temporary)->toBeTrue()
        ->and($temporary->performance_plan_id)->toBeNull()
        ->and($this->employee->fresh()->current_assignment_id)->toBe($this->assignment->id)
        ->and($agreement->fresh()->closed_at)->toBeNull();
});

test('assignment snapshots still select their historical unit plan after a structural position move', function (): void {
    $original = assignmentContextPlan();
    $this->position->update(['organization_unit_id' => $this->otherUnit->id]);
    assignmentContextPlan(['organization_unit_id' => $this->otherUnit->id], 2);

    $agreement = app(EmployeeAgreementService::class)->create($this->employee, $this->assignment, $this->cycle, $this->actor);

    expect($agreement->performance_plan_id)->toBe($original->id)
        ->and($agreement->organization_unit_id)->toBe($this->unit->id);
});
