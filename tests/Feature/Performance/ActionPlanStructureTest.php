<?php

declare(strict_types=1);

use App\Models\Kpi;
use App\Models\KpiTarget;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\StrategicGoal;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The plan page carries everything the action-plan table (ድርጊት መርሃ ግብር)
 * is drawn from: goal → weight %, row → number, weight, unit, baseline,
 * target, and the schedule by month (or quarter) counted from the cycle start.
 */
beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00'));
    $type = OrganizationType::query()->create(['code' => 'AP-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AP-ORG', 'name_en' => 'Bureau', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'AP-U', 'name_en' => 'Planning', 'unit_type' => 'directorate', 'status' => 'active']);
    $role = Role::findOrCreate('Action Plan Planner', 'web');
    foreach (array_column(require database_path('seeders/data/performance-permissions.php'), 'name') as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->planner = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $this->planner->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
});

it('gives the plan page every column of the action-plan table', function (): void {
    $as = fn () => $this->actingAs($this->planner)->from('/performance');

    // A fiscal year from ሐምሌ 1 (2026-07-08) to ሰኔ 30 (2027-07-07): month 1 is ሐምሌ.
    $as()->post(route('performance.cycles.store'), ['code' => 'AP-FY', 'name_en' => 'FY', 'organization_id' => $this->org->id, 'start_date' => '2026-07-08', 'end_date' => '2027-07-07'])->assertSessionHasNoErrors();
    $cycle = PerformanceCycle::query()->where('code', 'AP-FY')->sole();
    foreach (['G1' => '60', 'G2' => '40'] as $code => $weight) {
        $as()->post(route('performance.strategic-goals.store'), ['cycle_id' => $cycle->id, 'organization_id' => $this->org->id, 'code' => $code, 'name_en' => "Goal {$code}", 'name_am' => "ግብ {$code}", 'weight_percent' => $weight])->assertSessionHasNoErrors();
    }
    $as()->post(route('performance.plans.store'), ['cycle_id' => $cycle->id, 'plan_type' => 'ORGANIZATION', 'organization_id' => $this->org->id, 'title' => 'Plan'])->assertSessionHasNoErrors();
    $plan = PerformancePlan::query()->sole();
    foreach ([['K-COUNT', 'COUNT', 'SUM', 'services'], ['K-PCT-A', 'PERCENTAGE', 'LATEST_VALUE', null], ['K-PCT-B', 'PERCENTAGE', 'LATEST_VALUE', null]] as [$code, $type, $aggregation, $unit]) {
        $as()->post(route('performance.kpis.store'), ['code' => $code, 'name_en' => $code, 'organization_id' => $this->org->id, 'measurement_type' => $type, 'unit_of_measure' => $unit, 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => $aggregation, 'data_source_type' => 'MANUAL', 'frequency' => 'MONTHLY', 'is_active' => true])->assertSessionHasNoErrors();
    }
    $goal = fn (string $code) => StrategicGoal::query()->where('code', $code)->value('id');
    $kpi = fn (string $code) => Kpi::query()->where('code', $code)->value('id');

    // Row 1-1: one KPI, a count, scheduled in three months only (the rest are "—").
    $as()->post(route('performance.plans.objectives.store', $plan), ['strategic_goal_id' => $goal('G1'), 'code' => '1-1', 'title_en' => 'Digitize services', 'weight' => 60, 'absolute_weight_percent' => 60])->assertSessionHasNoErrors();
    $single = PerformanceObjective::query()->where('code', '1-1')->sole();
    $as()->post(route('performance.objectives.targets.store', $single), ['kpi_id' => $kpi('K-COUNT'), 'baseline_value' => 377, 'target_value' => 40, 'weight' => 100])->assertSessionHasNoErrors();
    $countTarget = KpiTarget::query()->where('objective_id', $single->id)->sole();
    $as()->put(route('performance.targets.period-targets.replace', $countTarget), ['period_targets' => [
        ['period_type' => 'MONTH', 'period_number' => 3, 'target_value' => 5],
        ['period_type' => 'MONTH', 'period_number' => 4, 'target_value' => 20],
        ['period_type' => 'MONTH', 'period_number' => 11, 'target_value' => 15],
    ]])->assertSessionHasNoErrors();

    // Row 2-1: an objective with two KPIs sharing its weight, scheduled by quarter.
    $as()->post(route('performance.plans.objectives.store', $plan), ['strategic_goal_id' => $goal('G2'), 'code' => '2-1', 'title_en' => 'Main activities', 'weight' => 40, 'absolute_weight_percent' => 40])->assertSessionHasNoErrors();
    $shared = PerformanceObjective::query()->where('code', '2-1')->sole();
    foreach (['K-PCT-A' => 75, 'K-PCT-B' => 25] as $code => $weight) {
        $as()->post(route('performance.objectives.targets.store', $shared), ['kpi_id' => $kpi($code), 'baseline_value' => 0, 'target_value' => 100, 'weight' => $weight])->assertSessionHasNoErrors();
    }
    $quarterly = KpiTarget::query()->where('objective_id', $shared->id)->where('kpi_id', $kpi('K-PCT-A'))->sole();
    $as()->put(route('performance.targets.period-targets.replace', $quarterly), ['period_targets' => collect([1 => 10, 2 => 10, 3 => 40, 4 => 40])->map(fn ($v, $q) => ['period_type' => 'QUARTER', 'period_number' => $q, 'target_value' => $v])->values()->all()])->assertSessionHasNoErrors();

    $as()->get(route('performance.plans.show', $plan))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Performance/Plans/Show')
        ->where('plan.cycle_period', ['2026-07-08', '2027-07-07'])
        ->where('strategicGoals.0.code', 'G1')->where('strategicGoals.0.weight_percent', '60.0000')
        ->where('objectives.0.code', '1-1')->where('objectives.0.strategic_goal_id', $goal('G1'))->where('objectives.0.absolute_weight_percent', '60.0000')
        ->where('objectives.0.targets.0.baseline_value', '377.0000')->where('objectives.0.targets.0.target_value', '40.0000')
        ->where('objectives.0.targets.0.kpi.unit', 'services')->where('objectives.0.targets.0.kpi.measurement', 'COUNT')
        ->has('objectives.0.targets.0.period_targets', 3)
        ->where('objectives.0.targets.0.period_targets.2.period_number', 11)
        ->has('objectives.1.targets', 2)
        ->where('objectives.1.targets.0.kpi.measurement', 'PERCENTAGE')
        ->has('objectives.1.targets.0.period_targets', 4)
        ->where('objectives.1.targets.0.period_targets.0.period_type', 'QUARTER'));

    // An empty schedule clears it.
    $as()->put(route('performance.targets.period-targets.replace', $countTarget), ['period_targets' => []])->assertSessionHasNoErrors();
    expect($countTarget->periodTargets()->count())->toBe(0);
});
