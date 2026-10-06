<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\Kpi;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\PerformancePlanScore;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\StrategicGoal;
use App\Models\StrategicGoalAllocation;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\PerformanceCascadeService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Reverse traceability (docs/performance-cascade.md): every answer is derived
 * from plan lineage. Positions and services never store a goal.
 */
beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'TR-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'TR-ORG', 'name_en' => 'Bureau', 'status' => 'active']);
    $this->hr = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'TR-HR', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->reform = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'TR-RF', 'name_en' => 'Reform Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->ict = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'TR-ICT', 'name_en' => 'ICT Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->positionA = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->hr->id, 'job_position_code' => 'TR-PA', 'title_en' => 'HR Officer', 'is_active' => true]);
    $this->positionB = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->reform->id, 'job_position_code' => 'TR-PB', 'title_en' => 'Reform Officer', 'is_active' => true]);
    $this->serviceA = PositionService::query()->create(['organization_id' => $this->org->id, 'position_id' => $this->positionA->id, 'service_no' => 'TR-SA', 'name_en' => 'Recruitment Service', 'is_active' => true]);
    $this->serviceB = PositionService::query()->create(['organization_id' => $this->org->id, 'position_id' => $this->positionB->id, 'service_no' => 'TR-SB', 'name_en' => 'Process Improvement', 'is_active' => true]);
    $this->cycle = PerformanceCycle::query()->create(['code' => 'TR-FY', 'name_en' => 'FY 2026', 'organization_id' => $this->org->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->goal = StrategicGoal::query()->create(['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'code' => 'TR-G', 'name_en' => 'Service quality', 'name_am' => 'Service quality', 'weight_percent' => '30', 'status' => 'PUBLISHED']);
    $this->allocHr = StrategicGoalAllocation::query()->create(['strategic_goal_id' => $this->goal->id, 'organization_unit_id' => $this->hr->id, 'organization_contribution_percent' => '15', 'allocation_type' => 'SHARED', 'is_lead' => true]);
    $this->allocReform = StrategicGoalAllocation::query()->create(['strategic_goal_id' => $this->goal->id, 'organization_unit_id' => $this->reform->id, 'organization_contribution_percent' => '10', 'allocation_type' => 'SHARED']);
    $this->allocIct = StrategicGoalAllocation::query()->create(['strategic_goal_id' => $this->goal->id, 'organization_unit_id' => $this->ict->id, 'organization_contribution_percent' => '5', 'allocation_type' => 'SHARED']);

    $this->orgPlan = traceabilityPlan($this, 'ORGANIZATION', null, null);
    $root = PerformanceObjective::query()->create(['performance_plan_id' => $this->orgPlan->id, 'strategic_goal_id' => $this->goal->id, 'code' => 'TR-ROOT', 'title_en' => 'Improve delivery', 'objective_type' => 'STRATEGIC', 'weight' => 30, 'absolute_weight_percent' => 30]);
    $this->hrPlan = traceabilityPlan($this, 'UNIT', $this->orgPlan, $this->hr);
    $this->hrObjective = PerformanceObjective::query()->create(['performance_plan_id' => $this->hrPlan->id, 'parent_objective_id' => $root->id, 'strategic_goal_id' => $this->goal->id, 'strategic_goal_allocation_id' => $this->allocHr->id, 'code' => 'TR-HR-O', 'title_en' => 'HR delivery', 'objective_type' => 'INHERITED', 'weight' => 100, 'local_weight_percent' => 100]);
    $this->reformPlan = traceabilityPlan($this, 'UNIT', $this->orgPlan, $this->reform);
    $reformObjective = PerformanceObjective::query()->create(['performance_plan_id' => $this->reformPlan->id, 'parent_objective_id' => $root->id, 'strategic_goal_id' => $this->goal->id, 'strategic_goal_allocation_id' => $this->allocReform->id, 'code' => 'TR-RF-O', 'title_en' => 'Reform delivery', 'objective_type' => 'INHERITED', 'weight' => 100, 'local_weight_percent' => 100]);
    $this->planA = traceabilityPlan($this, 'POSITION', $this->hrPlan, $this->hr, $this->positionA);
    $this->itemA = PerformanceObjective::query()->create(['performance_plan_id' => $this->planA->id, 'parent_objective_id' => $this->hrObjective->id, 'strategic_goal_id' => $this->goal->id, 'strategic_goal_allocation_id' => $this->allocHr->id, 'position_service_id' => $this->serviceA->id, 'code' => 'TR-A', 'title_en' => 'Recruit within standard', 'objective_type' => 'INHERITED', 'weight' => 100, 'local_weight_percent' => 100]);
    $this->planB = traceabilityPlan($this, 'POSITION', $this->reformPlan, $this->reform, $this->positionB);
    $this->itemB = PerformanceObjective::query()->create(['performance_plan_id' => $this->planB->id, 'parent_objective_id' => $reformObjective->id, 'strategic_goal_id' => $this->goal->id, 'strategic_goal_allocation_id' => $this->allocReform->id, 'position_service_id' => $this->serviceB->id, 'code' => 'TR-B', 'title_en' => 'Redesign processes', 'objective_type' => 'INHERITED', 'weight' => 100, 'local_weight_percent' => 100]);

    $role = Role::findOrCreate('Traceability Planner', 'web');
    foreach ([...array_column(require database_path('seeders/data/performance-permissions.php'), 'name'), 'positions.view'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->planner = User::factory()->create(['status' => 'active'])->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $this->planner->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->cascade = app(PerformanceCascadeService::class);
});

function traceabilityPlan($test, string $type, ?PerformancePlan $parent, ?OrganizationUnit $unit, ?Position $position = null, ?PerformanceCycle $cycle = null, string $status = 'PUBLISHED'): PerformancePlan
{
    $cycle ??= $test->cycle;
    $plan = new PerformancePlan(['cycle_id' => $cycle->id, 'organization_id' => $test->org->id, 'plan_type' => $type, 'organization_unit_id' => $unit?->id, 'position_id' => $position?->id, 'parent_plan_id' => $parent?->id, 'title' => "{$type} plan {$cycle->code}", 'effective_from' => $cycle->start_date, 'effective_to' => $cycle->end_date]);
    $plan->forceFill(['lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'status' => $status])->save();

    return $plan;
}

function traceabilityAgreement($test, Employee $employee, Position $position, OrganizationUnit $unit, PerformancePlan $plan, PerformanceObjective $objective, array $period, bool $current): EmployeePerformanceAgreement
{
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $test->org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'assignment_status' => $current ? 'active' : 'closed', 'effective_from' => $period[0], 'effective_to' => $current ? null : $period[1], 'is_current' => $current]);
    $agreement = EmployeePerformanceAgreement::query()->create(['cycle_id' => $test->cycle->id, 'employee_id' => $employee->id, 'employee_assignment_id' => $assignment->id, 'performance_plan_id' => $plan->id, 'organization_id' => $test->org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'manager_user_id' => $test->planner->id, 'effective_from' => $period[0], 'effective_to' => $period[1]]);
    $kpi = Kpi::query()->firstOrCreate(['code' => 'TR-K'], ['name_en' => 'Cases', 'organization_id' => $test->org->id, 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL']);
    EmployeePerformanceItem::query()->create(['agreement_id' => $agreement->id, 'objective_id' => $objective->id, 'kpi_id' => $kpi->id, 'expected_output' => 'Output', 'weight' => 100, 'target_value' => 10]);

    return $agreement;
}

it('resolves the goals a position contributes to in each cycle through its plans', function (): void {
    $next = PerformanceCycle::query()->create(['code' => 'TR-FY2', 'name_en' => 'FY 2027', 'organization_id' => $this->org->id, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $nextUnit = traceabilityPlan($this, 'UNIT', null, $this->hr, null, $next);
    $local = PerformanceObjective::query()->create(['performance_plan_id' => $nextUnit->id, 'code' => 'TR-LOCAL', 'title_en' => 'Local operations', 'objective_type' => 'LOCAL', 'weight' => 100]);
    $nextPlan = traceabilityPlan($this, 'POSITION', $nextUnit, $this->hr, $this->positionA, $next, 'DRAFT');
    PerformanceObjective::query()->create(['performance_plan_id' => $nextPlan->id, 'parent_objective_id' => $local->id, 'position_service_id' => $this->serviceA->id, 'code' => 'TR-A2', 'title_en' => 'Reduce processing time', 'objective_type' => 'INHERITED', 'weight' => 100]);

    $rows = collect($this->cascade->goalsForPosition($this->positionA))->keyBy('cycle_id');
    expect($rows[$this->cycle->id]['goals'])->toHaveCount(1)
        ->and($rows[$this->cycle->id]['goals'][0])->toMatchArray(['id' => $this->goal->id, 'code' => 'TR-G', 'weight_percent' => '30.0000', 'allocation_id' => $this->allocHr->id, 'allocation_percent' => '15.0000'])
        ->and($rows[$this->cycle->id]['goals'][0]['unit'])->toBe('HR Directorate')
        // The same service in a later cycle supports only local work: no goal is inherited from master data.
        ->and($rows[$next->id]['goals'])->toBe([])
        ->and($this->cascade->goalsForPosition($this->positionA, $next->id))->toHaveCount(1);
});

it('resolves contributing positions and services from a shared strategic goal', function (): void {
    $positions = collect($this->cascade->goalCascade($this->goal, $this->planner))->where('level', 'POSITION');
    expect($positions->pluck('position')->sort()->values()->all())->toBe(['HR Officer', 'Reform Officer'])
        ->and($positions->pluck('service')->sort()->values()->all())->toBe(['Process Improvement', 'Recruitment Service'])
        ->and($positions->pluck('unit')->sort()->values()->all())->toBe(['HR Directorate', 'Reform Directorate']);
});

it('resolves an employee goal history across a transfer without moving old context', function (): void {
    $employee = Employee::query()->create(['employee_number' => 'TR-E', 'first_name' => 'Test', 'last_name' => 'Person', 'full_name' => 'Test Person', 'status' => 'active']);
    $old = traceabilityAgreement($this, $employee, $this->positionA, $this->hr, $this->planA, $this->itemA, ['2026-01-01', '2026-06-30'], false);
    $new = traceabilityAgreement($this, $employee, $this->positionB, $this->reform, $this->planB, $this->itemB, ['2026-07-01', '2026-12-31'], true);

    $rows = collect($this->cascade->goalsForEmployee($employee))->keyBy('agreement_id');
    expect($rows)->toHaveCount(2)
        ->and($rows[$old->id])->toMatchArray(['position_id' => $this->positionA->id, 'organization_unit_id' => $this->hr->id, 'performance_plan_id' => $this->planA->id, 'effective_to' => '2026-06-30'])
        ->and($rows[$old->id]['goals'][0]['allocation_id'])->toBe($this->allocHr->id)
        ->and($rows[$new->id])->toMatchArray(['position_id' => $this->positionB->id, 'performance_plan_id' => $this->planB->id])
        ->and($rows[$new->id]['goals'][0]['allocation_id'])->toBe($this->allocReform->id)
        ->and($rows[$old->id]['goals'][0]['id'])->toBe($rows[$new->id]['goals'][0]['id']);
});

it('derives allocation contribution in organization percentage points from the unit plan score', function (): void {
    PerformancePlanScore::query()->create(['performance_plan_id' => $this->hrPlan->id, 'cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->hr->id, 'as_of' => '2026-03-31', 'score' => '50', 'trace_json' => ['objectives' => [['objective_id' => $this->hrObjective->id, 'weight' => '100.0000', 'score' => '50.0000', 'targets' => [['achievement' => '50.0000']]]]], 'calculated_at' => now()]);
    PerformancePlanScore::query()->create(['performance_plan_id' => $this->hrPlan->id, 'cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->hr->id, 'as_of' => '2026-06-30', 'score' => '90', 'trace_json' => ['objectives' => [['objective_id' => $this->hrObjective->id, 'weight' => '100.0000', 'score' => '90.0000', 'targets' => [['achievement' => '90.0000']]]]], 'calculated_at' => now()]);

    $result = $this->cascade->allocationContributions([$this->goal]);
    expect($result['allocations'][$this->allocHr->id])->toMatchArray(['plan_id' => $this->hrPlan->id, 'as_of' => '2026-06-30', 'achievement' => '90.0000', 'contribution' => '13.5000', 'complete' => true])
        // Not 90 points, not 30 × 90: an unmeasured allocation stays unmeasured instead of counting as zero.
        ->and($result['allocations'][$this->allocReform->id])->toMatchArray(['achievement' => null, 'contribution' => null])
        ->and($result['allocations'][$this->allocIct->id])->toMatchArray(['plan_id' => null, 'contribution' => null])
        ->and($result['goals'][$this->goal->id])->toBe(['contribution' => '13.5000', 'complete' => false]);
});

it('shows derived goals beside position plans and contributions beside allocations', function (): void {
    PerformancePlanScore::query()->create(['performance_plan_id' => $this->hrPlan->id, 'cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->hr->id, 'as_of' => '2026-06-30', 'score' => '90', 'trace_json' => ['objectives' => [['objective_id' => $this->hrObjective->id, 'weight' => '100.0000', 'score' => '90.0000', 'targets' => [['achievement' => '90.0000']]]]], 'calculated_at' => now()]);
    $this->travelTo('2026-10-05');

    $this->actingAs($this->planner)->get(route('positions.show', $this->positionA))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentPerformancePlans.0.id', $this->planA->id)
            ->where('currentPerformancePlans.0.goals.0.code', 'TR-G')
            ->where('currentPerformancePlans.0.goals.0.allocation_percent', '15.0000')
            ->missing('position.strategic_goal_id'));

    $this->actingAs($this->planner)->get(route('performance.strategic-goals.index', ['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('goals.data.0.allocations', fn ($rows) => collect($rows)->firstWhere('id', $this->allocHr->id)['contribution']['contribution'] === '13.5000')
            ->where('goals.data.0.contribution', ['contribution' => '13.5000', 'complete' => false]));
});
