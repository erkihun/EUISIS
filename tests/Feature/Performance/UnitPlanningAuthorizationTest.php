<?php

declare(strict_types=1);

use App\Models\DailyActivityReviewerAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\EpmsAccess;
use App\Services\Performance\PerformancePlanService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'UA-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'UA-ORG', 'name_en' => 'Bureau', 'status' => 'active']);
    $this->otherOrg = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'UA-OTHER', 'name_en' => 'Other bureau', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'UA-U', 'name_en' => 'HR', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->otherUnit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'UA-OU', 'name_en' => 'Records', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->cycle = PerformanceCycle::query()->create(['code' => 'UA-FY', 'name_en' => 'FY', 'organization_id' => $this->org->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->plan = new PerformancePlan(['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'plan_type' => 'UNIT', 'title' => 'HR plan']);
    $this->plan->forceFill(['lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'status' => 'DRAFT'])->save();
    $this->access = app(EpmsAccess::class);
    $this->plans = app(PerformancePlanService::class);
});

function unitPlanningActor($test, ?OrganizationUnit $unit, array $permissions = ['performance_objectives.manage', 'performance_plans.update'], ?Organization $organization = null): User
{
    $user = User::factory()->create(['status' => 'active']);
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => ($organization ?? $test->org)->id, 'scope_type' => 'self', 'is_active' => true]);
    if ($unit !== null) {
        DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $user->id, 'organization_id' => $unit->organization_id, 'organization_unit_id' => $unit->id, 'include_sub_units' => true, 'is_active' => true]);
    }

    return $user;
}

it('allows an explicitly assigned unit manager to edit only covered planning', function (): void {
    $manager = unitPlanningActor($this, $this->unit);
    expect($this->access->canPlan($manager, 'performance_objectives.manage', $this->org->id, $this->unit->id))->toBeTrue()
        ->and($this->access->canPlan($manager, 'performance_objectives.manage', $this->org->id, $this->otherUnit->id))->toBeFalse();
    $objective = $this->plans->addObjective($this->plan, ['code' => 'UA-OK', 'title_en' => 'HR records', 'weight' => 100], $manager);
    expect($objective->performance_plan_id)->toBe($this->plan->id);
});

it('blocks another unit manager and an employee even when they possess a mutation permission', function (): void {
    foreach ([unitPlanningActor($this, $this->otherUnit), unitPlanningActor($this, null)] as $actor) {
        expect($this->access->canPlan($actor, 'performance_objectives.manage', $this->org->id, $this->unit->id))->toBeFalse();
        expect(fn () => $this->plans->addObjective($this->plan, ['code' => 'UA-BAD', 'title_en' => 'Unauthorized', 'weight' => 100], $actor))->toThrow(AuthorizationException::class);
    }
    expect(PerformanceObjective::query()->count())->toBe(0);
});

it('requires mutation permission as well as reviewer coverage', function (): void {
    $reviewer = unitPlanningActor($this, $this->unit, ['performance_plans.view']);
    expect($this->access->canPlan($reviewer, 'performance_objectives.manage', $this->org->id, $this->unit->id))->toBeFalse();
    expect(fn () => $this->plans->addObjective($this->plan, ['code' => 'UA-BAD', 'title_en' => 'Unauthorized', 'weight' => 100], $reviewer))->toThrow(AuthorizationException::class);
});

it('permits scoped oversight but blocks cross organization planning', function (): void {
    $oversight = unitPlanningActor($this, null, ['performance_objectives.manage', 'performance_plans.approve']);
    expect($this->access->canPlan($oversight, 'performance_objectives.manage', $this->org->id, $this->unit->id))->toBeTrue()
        ->and($this->access->canPlan($oversight, 'performance_objectives.manage', $this->otherOrg->id, null))->toBeFalse();
    $foreign = unitPlanningActor($this, null, ['performance_objectives.manage', 'performance_plans.approve'], $this->otherOrg);
    expect(fn () => $this->plans->addObjective($this->plan, ['code' => 'UA-FOREIGN', 'title_en' => 'Unauthorized', 'weight' => 100], $foreign))->toThrow(AuthorizationException::class);
});

it('includes authorized descendant units without granting organization master planning', function (): void {
    $team = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'parent_unit_id' => $this->unit->id, 'code' => 'UA-TEAM', 'name_en' => 'Team', 'unit_type' => 'team', 'status' => 'active']);
    $manager = unitPlanningActor($this, $this->unit);
    expect($this->access->canPlan($manager, 'performance_plans.update', $this->org->id, $team->id))->toBeTrue()
        ->and($this->access->canPlan($manager, 'performance_plans.update', $this->org->id, null))->toBeFalse();
});


it('enforces unit coverage for target actuals amendments and period schedule writes', function (): void {
    $actor = unitPlanningActor($this, $this->otherUnit, ['kpi_actuals.enter', 'kpi_actuals.verify', 'kpi_targets.manage']);
    $objective = PerformanceObjective::query()->create(['performance_plan_id' => $this->plan->id, 'code' => 'UA-TARGET', 'title_en' => 'HR delivery', 'objective_type' => 'LOCAL', 'weight' => 100]);
    $kpi = \App\Models\Kpi::query()->create(['code' => 'UA-KPI', 'name_en' => 'Count', 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL']);
    $target = \App\Models\KpiTarget::query()->create(['performance_plan_id' => $this->plan->id, 'objective_id' => $objective->id, 'kpi_id' => $kpi->id, 'target_value' => 10, 'weight' => 100, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
    expect(fn () => app(\App\Services\Performance\StrategicPlanningService::class)->replacePeriodTargets($target, [], $actor))->toThrow(AuthorizationException::class);
    $this->plan->forceFill(['status' => 'PUBLISHED'])->save();
    $target->unsetRelation('plan');
    expect(fn () => app(\App\Services\Performance\KpiActualService::class)->recordForTarget($target, ['period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'actual_value' => 5], $actor))->toThrow(AuthorizationException::class);
    $actual = \App\Models\KpiActual::query()->create(['subject_key' => 'target:'.$target->id, 'source_key' => 'manual', 'source_type' => 'MANUAL', 'kpi_id' => $kpi->id, 'target_id' => $target->id, 'performance_plan_id' => $this->plan->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'actual_value' => 5]);
    expect(fn () => app(\App\Services\Performance\KpiActualService::class)->verify($actual, $actor))->toThrow(AuthorizationException::class);
    expect(fn () => app(\App\Services\Performance\TargetAmendmentService::class)->request($target, ['target_value' => 20], 'Change', '2026-02-01', $actor))->toThrow(AuthorizationException::class);
});

it('does not disclose named-manager agreements without agreement viewing authority', function (): void {
    $actor = unitPlanningActor($this, null, ['performance_plans.view', 'strategic_goals.view']);
    $employee = \App\Models\Employee::query()->create(['employee_number' => 'UA-EMP', 'first_name' => 'Private', 'last_name' => 'Employee', 'full_name' => 'Private Employee', 'status' => 'active']);
    $assignment = \App\Models\EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
    $agreement = \App\Models\EmployeePerformanceAgreement::query()->create(['cycle_id' => $this->cycle->id, 'employee_id' => $employee->id, 'employee_assignment_id' => $assignment->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'manager_user_id' => $actor->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    expect($this->access->canViewAgreement($actor, $agreement))->toBeFalse()
        ->and($this->access->constrainAgreements(\App\Models\EmployeePerformanceAgreement::query(), $actor)->count())->toBe(0);
});
