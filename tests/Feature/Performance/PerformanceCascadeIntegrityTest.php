<?php

declare(strict_types=1);

use App\Models\DailyActivityItem;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\Kpi;
use App\Models\KpiPeriodTarget;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\StrategicGoal;
use App\Models\StrategicGoalAllocation;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\PerformanceCascadeService;
use App\Services\Performance\PerformancePlanService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'CI-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'CI-ORG', 'name_en' => 'Bureau', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'CI-U', 'name_en' => 'HR', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->otherUnit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'CI-OTHER', 'name_en' => 'Other unit', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'CI-P', 'title_en' => 'HR Officer', 'is_active' => true]);
    $this->otherPosition = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->otherUnit->id, 'job_position_code' => 'CI-OP', 'title_en' => 'Other officer', 'is_active' => true]);
    $this->service = PositionService::query()->create(['organization_id' => $this->org->id, 'position_id' => $this->position->id, 'service_no' => 'CI-S', 'name_en' => 'Recruitment', 'is_active' => true]);
    $this->otherService = PositionService::query()->create(['organization_id' => $this->org->id, 'position_id' => $this->otherPosition->id, 'service_no' => 'CI-OS', 'name_en' => 'Other service', 'is_active' => true]);
    $this->cycle = PerformanceCycle::query()->create(['code' => 'CI-FY', 'name_en' => 'FY', 'organization_id' => $this->org->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->goal = StrategicGoal::query()->create(['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'code' => 'CI-G', 'name_en' => 'Service quality', 'name_am' => 'Service quality', 'weight_percent' => '30']);
    $this->allocation = StrategicGoalAllocation::query()->create(['strategic_goal_id' => $this->goal->id, 'organization_unit_id' => $this->unit->id, 'organization_contribution_percent' => '15', 'allocation_type' => 'SHARED', 'is_lead' => true]);
    $role = Role::findOrCreate('Cascade Integrity Planner', 'web');
    foreach (array_column(require database_path('seeders/data/performance-permissions.php'), 'name') as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->planner = User::factory()->create(['status' => 'active'])->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $this->planner->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->plans = app(PerformancePlanService::class);
    $this->cascade = app(PerformanceCascadeService::class);
    $this->orgPlan = integrityPlan($this, 'ORGANIZATION', null, 'PUBLISHED');
    $this->rootObjective = PerformanceObjective::query()->create(['performance_plan_id' => $this->orgPlan->id, 'strategic_goal_id' => $this->goal->id, 'code' => 'CI-ROOT', 'title_en' => 'Improve delivery', 'objective_type' => 'STRATEGIC', 'weight' => 30, 'absolute_weight_percent' => 30]);
    $this->unitPlan = integrityPlan($this, 'UNIT', $this->orgPlan, 'PUBLISHED');
    $this->unitObjective = PerformanceObjective::query()->create(['performance_plan_id' => $this->unitPlan->id, 'parent_objective_id' => $this->rootObjective->id, 'strategic_goal_id' => $this->goal->id, 'strategic_goal_allocation_id' => $this->allocation->id, 'code' => 'CI-UNIT', 'title_en' => 'HR delivery', 'objective_type' => 'INHERITED', 'weight' => 100, 'local_weight_percent' => 100]);
    $this->positionPlan = integrityPlan($this, 'POSITION', $this->unitPlan);
});

function integrityPlan($test, string $type, ?PerformancePlan $parent = null, string $status = 'DRAFT', ?PerformanceCycle $cycle = null): PerformancePlan
{
    $plan = new PerformancePlan(['cycle_id' => ($cycle ?? $test->cycle)->id, 'organization_id' => $test->org->id, 'plan_type' => $type, 'organization_unit_id' => $type === 'ORGANIZATION' ? null : $test->unit->id, 'position_id' => $type === 'POSITION' ? $test->position->id : null, 'parent_plan_id' => $parent?->id, 'title' => "{$type} plan", 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    $plan->forceFill(['lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'status' => $status])->save();

    return $plan;
}

function integrityItem($test, string $code = 'CI-ITEM', array $extra = []): PerformanceObjective
{
    return $test->plans->addObjective($test->positionPlan, [...['code' => $code, 'title_en' => 'Recruitment delivery', 'parent_objective_id' => $test->unitObjective->id, 'position_service_id' => $test->service->id, 'weight' => '100', 'local_weight_percent' => '100'], ...$extra], $test->planner);
}

it('keeps positions and services independent of annual strategic goals', function (): void {
    expect(Schema::hasColumn('positions', 'strategic_goal_id'))->toBeFalse()
        ->and(Schema::hasColumn('position_services', 'strategic_goal_id'))->toBeFalse()
        ->and($this->service->position->id)->toBe($this->position->id);
});

it('reuses one position service across multiple plan items and cycles', function (): void {
    $first = integrityItem($this, 'CI-FIRST', ['weight' => 50, 'local_weight_percent' => 50]);
    $second = integrityItem($this, 'CI-SECOND', ['weight' => 50, 'local_weight_percent' => 50]);
    $cycle = PerformanceCycle::query()->create(['code' => 'CI-NEXT', 'name_en' => 'Next FY', 'organization_id' => $this->org->id, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $nextUnitPlan = integrityPlan($this, 'UNIT', null, 'PUBLISHED', $cycle);
    $nextParent = PerformanceObjective::query()->create(['performance_plan_id' => $nextUnitPlan->id, 'code' => 'NEXT-U', 'title_en' => 'New annual responsibility', 'objective_type' => 'LOCAL', 'weight' => 100]);
    $this->positionPlan = integrityPlan($this, 'POSITION', $nextUnitPlan, 'DRAFT', $cycle);
    $this->unitObjective = $nextParent;
    $third = integrityItem($this, 'CI-NEXT-ITEM');
    expect([$first->position_service_id, $second->position_service_id, $third->position_service_id])->toBe(array_fill(0, 3, $this->service->id))
        ->and(PositionService::query()->count())->toBe(2)
        ->and($first->plan->cycle_id)->toBe($this->cycle->id)
        ->and($third->plan->cycle_id)->toBe($cycle->id);
});

it('rejects foreign position services and direct goal shortcuts in position items', function (): void {
    expect(fn () => integrityItem($this, 'BAD-SERVICE', ['position_service_id' => $this->otherService->id]))->toThrow(ValidationException::class);
    expect(fn () => integrityItem($this, 'BAD-GOAL', ['parent_objective_id' => null, 'strategic_goal_id' => $this->goal->id]))->toThrow(ValidationException::class);
    expect($this->positionPlan->objectives()->count())->toBe(0);
});

it('rejects unrelated unit and cycle plan references on the backend', function (): void {
    $unrelated = integrityPlan($this, 'UNIT', $this->orgPlan, 'PUBLISHED');
    $unrelated->update(['organization_unit_id' => $this->otherUnit->id]);
    expect(fn () => $this->plans->create(['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'plan_type' => 'POSITION', 'position_id' => $this->position->id, 'parent_plan_id' => $unrelated->id, 'title' => 'Tampered'], $this->planner))->toThrow(ValidationException::class);
    $foreign = PerformanceObjective::query()->create(['performance_plan_id' => $unrelated->id, 'code' => 'FOREIGN-U', 'title_en' => 'Unrelated', 'objective_type' => 'LOCAL', 'weight' => 100]);
    expect(fn () => integrityItem($this, 'BAD-UPSTREAM', ['parent_objective_id' => $foreign->id]))->toThrow(ValidationException::class);
    $nextCycle = PerformanceCycle::query()->create(['code' => 'CI-WRONG', 'name_en' => 'Wrong FY', 'organization_id' => $this->org->id, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $badPlan = integrityPlan($this, 'POSITION', $this->unitPlan, 'DRAFT', $nextCycle);
    expect(fn () => $this->cascade->links($badPlan, ['weight' => 100], $this->unitObjective))->toThrow(ValidationException::class);
});

it('rejects a forged allocation and refuses positions without unit planning', function (): void {
    $otherAllocation = StrategicGoalAllocation::query()->create(['strategic_goal_id' => $this->goal->id, 'organization_unit_id' => $this->otherUnit->id, 'organization_contribution_percent' => '10', 'allocation_type' => 'SHARED']);
    expect(fn () => integrityItem($this, 'BAD-ALLOCATION', ['strategic_goal_allocation_id' => $otherAllocation->id]))->toThrow(ValidationException::class);
    $orphan = Position::query()->create(['organization_id' => $this->org->id, 'job_position_code' => 'CI-ORPHAN', 'title_en' => 'Unassigned post', 'is_active' => true]);
    expect(fn () => $this->plans->create(['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'plan_type' => 'POSITION', 'position_id' => $orphan->id, 'parent_plan_id' => $this->orgPlan->id, 'title' => 'Skipped unit'], $this->planner))->toThrow(ValidationException::class);
});

it('retains allocated ancestor responsibility for descendant unit plans', function (): void {
    $team = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'parent_unit_id' => $this->unit->id, 'code' => 'CI-TEAM', 'name_en' => 'HR team', 'unit_type' => 'team', 'status' => 'active']);
    $child = integrityPlan($this, 'UNIT', $this->unitPlan);
    $child->update(['organization_unit_id' => $team->id]);
    $links = $this->cascade->links($child, ['weight' => 100], $this->unitObjective);
    expect($links['strategic_goal_allocation_id'])->toBe($this->allocation->id);
});

it('separates allocation percentage points from local position weights', function (): void {
    $item = integrityItem($this);
    expect($item->weight)->toBe('100.0000')->and($item->local_weight_percent)->toBe('100.0000')
        ->and($item->allocation->organization_contribution_percent)->toBe('15.0000')
        ->and($this->cascade->contribution('15', '90'))->toBe('13.5000');
    expect(fn () => integrityItem($this, 'BAD-WEIGHT', ['local_weight_percent' => 15]))->toThrow(ValidationException::class);
});

it('resolves historical goals allocations units and services from employee daily evidence', function (): void {
    $objective = integrityItem($this);
    $employee = Employee::query()->create(['employee_number' => 'CI-E', 'first_name' => 'Test', 'last_name' => 'Person', 'full_name' => 'Test Person', 'status' => 'active']);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
    $agreement = EmployeePerformanceAgreement::query()->create(['cycle_id' => $this->cycle->id, 'employee_id' => $employee->id, 'employee_assignment_id' => $assignment->id, 'performance_plan_id' => $this->positionPlan->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'manager_user_id' => $this->planner->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    $kpi = Kpi::query()->create(['code' => 'CI-K', 'name_en' => 'Cases', 'organization_id' => $this->org->id, 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL']);
    $item = EmployeePerformanceItem::query()->create(['agreement_id' => $agreement->id, 'objective_id' => $objective->id, 'kpi_id' => $kpi->id, 'expected_output' => 'Completed requests', 'weight' => 100, 'target_value' => 10]);
    $activity = new DailyActivityItem(['employee_performance_item_id' => $item->id, 'title' => 'Completed recruitment']);
    $steps = $this->cascade->activityTrace($activity);
    expect(array_column($steps, 'type'))->toBe(['GOAL', 'ALLOCATION', 'ORGANIZATION', 'UNIT', 'POSITION', 'SERVICE'])
        ->and($steps[0]['id'])->toBe($this->goal->id)->and($steps[1]['id'])->toBe($this->allocation->id)
        ->and($this->cascade->goalFor($objective)?->id)->toBe($this->goal->id);
    $rows = $this->cascade->goalCascade($this->goal, $this->planner);
    expect(collect($rows)->firstWhere('id', $objective->id)['employees'])->toBe(['Test Person']);
});

it('copies period targets into a new plan version without rewriting old unit context', function (): void {
    $objective = integrityItem($this);
    $kpi = Kpi::query()->create(['code' => 'CI-VK', 'name_en' => 'Cases', 'organization_id' => $this->org->id, 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL', 'frequency' => 'ANNUAL']);
    $target = $this->plans->addTarget($objective, ['kpi_id' => $kpi->id, 'weight' => 100, 'target_value' => 10], $this->planner);
    KpiPeriodTarget::query()->create(['kpi_target_id' => $target->id, 'period_type' => 'QUARTER', 'period_number' => 1, 'target_value' => '2.0000']);
    $this->positionPlan->forceFill(['status' => 'PUBLISHED'])->save();
    $this->position->update(['organization_unit_id' => $this->otherUnit->id]);
    $next = $this->plans->newVersion($this->positionPlan, 'Reviewed target', $this->planner);
    expect($this->positionPlan->fresh()->organization_unit_id)->toBe($this->unit->id)
        ->and($next->organization_unit_id)->toBe($this->unit->id)
        ->and($next->objectives()->first()->position_service_id)->toBe($this->service->id)
        ->and($next->targets()->first()->periodTargets()->first()->target_value)->toBe('2.0000')
        ->and($target->fresh()->periodTargets()->count())->toBe(1);
});

it('retains inactive historical services while refusing newly selected inactive services', function (): void {
    $objective = integrityItem($this);
    $this->service->update(['is_active' => false]);
    $this->plans->updateObjective($objective, ['title_en' => 'Reviewed wording'], $this->planner);
    expect($objective->fresh()->position_service_id)->toBe($this->service->id);
    expect(fn () => integrityItem($this, 'CI-INACTIVE'))->toThrow(ValidationException::class);
});

it('remaps a new child version to explicit upstream successors and preserves old links', function (): void {
    $objective = integrityItem($this);
    $kpi = Kpi::query()->create(['code' => 'CI-AMEND-K', 'name_en' => 'Cases', 'organization_id' => $this->org->id, 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL']);
    $parentTarget = $this->plans->addTarget($objective, ['kpi_id' => $kpi->id, 'weight' => 100, 'target_value' => 10], $this->planner);
    $this->positionPlan->forceFill(['status' => 'PUBLISHED'])->save();
    $unitVersion = $this->plans->newVersion($this->unitPlan, 'Unit amendment', $this->planner);
    $unitVersion->forceFill(['status' => 'PUBLISHED'])->save();
    $this->unitPlan->forceFill(['status' => 'SUPERSEDED'])->save();
    $next = $this->plans->newVersion($this->positionPlan, 'Adapt upstream amendment', $this->planner, $unitVersion->id);
    $copy = $next->objectives()->first();
    expect($next->parent_plan_id)->toBe($unitVersion->id)
        ->and($copy->parent_objective_id)->toBe($unitVersion->objectives()->first()->id)
        ->and($copy->source_objective_id)->toBe($objective->id)
        ->and($copy->strategic_goal_allocation_id)->toBe($this->allocation->id)
        ->and($objective->fresh()->parent_objective_id)->toBe($this->unitObjective->id)
        ->and($this->positionPlan->fresh()->parent_plan_id)->toBe($this->unitPlan->id)
        ->and($parentTarget->fresh()->performance_plan_id)->toBe($this->positionPlan->id);
});

it('rolls back an amendment when the upstream lineage has ambiguous successors', function (): void {
    integrityItem($this);
    $this->positionPlan->forceFill(['status' => 'PUBLISHED'])->save();
    $unitVersion = $this->plans->newVersion($this->unitPlan, 'Unit amendment', $this->planner);
    $original = $unitVersion->objectives()->first();
    $duplicate = $original->replicate();
    $duplicate->forceFill(['code' => 'CI-DUPLICATE'])->save();
    $unitVersion->forceFill(['status' => 'PUBLISHED'])->save();
    $before = PerformancePlan::query()->count();
    expect(fn () => $this->plans->newVersion($this->positionPlan, 'Ambiguous', $this->planner, $unitVersion->id))->toThrow(ValidationException::class);
    expect(PerformancePlan::query()->count())->toBe($before)
        ->and($this->positionPlan->fresh()->status->value)->toBe('PUBLISHED');
});


it('requires only mandatory strategic objectives allocated to the receiving unit', function (): void {
    $this->unitPlan->forceFill(['status' => 'DRAFT'])->save();
    $this->rootObjective->update(['is_mandatory' => true]);
    $goal = StrategicGoal::query()->create(['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'code' => 'CI-OTHER-G', 'name_en' => 'Other unit priority', 'name_am' => 'Other priority', 'weight_percent' => 70]);
    StrategicGoalAllocation::query()->create(['strategic_goal_id' => $goal->id, 'organization_unit_id' => $this->otherUnit->id, 'organization_contribution_percent' => 70, 'allocation_type' => 'PRIMARY', 'is_lead' => true]);
    $unallocated = PerformanceObjective::query()->create(['performance_plan_id' => $this->orgPlan->id, 'strategic_goal_id' => $goal->id, 'code' => 'CI-UNALLOCATED', 'title_en' => 'Other delivery', 'objective_type' => 'STRATEGIC', 'weight' => 70, 'is_mandatory' => true]);
    $problems = $this->plans->validate($this->unitPlan);
    expect(collect($problems)->filter(fn ($problem) => str_contains($problem, $unallocated->code)))->toBeEmpty();
});

it('refuses inconsistent absolute organization weights and mismatched upstream KPI objectives', function (): void {
    $this->orgPlan->forceFill(['status' => 'DRAFT'])->save();
    expect(fn () => $this->plans->addObjective($this->orgPlan, ['code' => 'CI-BAD-ABS', 'title_en' => 'Bad weight', 'weight' => 50, 'absolute_weight_percent' => 30], $this->planner))->toThrow(ValidationException::class);
    $kpi = Kpi::query()->create(['code' => 'CI-LINK-K', 'name_en' => 'Cases', 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL']);
    $otherObjective = $this->unitPlan->objectives()->create(['code' => 'CI-UPSTREAM-OTHER', 'title_en' => 'Other delivery', 'objective_type' => 'LOCAL', 'weight' => 50]);
    $otherTarget = $otherObjective->targets()->create(['performance_plan_id' => $this->unitPlan->id, 'kpi_id' => $kpi->id, 'weight' => 100, 'target_value' => 10, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
    $objective = integrityItem($this);
    expect(fn () => $this->plans->addTarget($objective, ['kpi_id' => $kpi->id, 'weight' => 100, 'target_value' => 10, 'parent_target_id' => $otherTarget->id], $this->planner))->toThrow(ValidationException::class);
});
