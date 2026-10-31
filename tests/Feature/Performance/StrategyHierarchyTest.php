<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Performance\Application\Services\StrategicPlanningService;
use Modules\Performance\Application\Services\StrategyHierarchyService;
use Modules\Performance\Domain\Models\MainStrategy;
use Modules\Performance\Domain\Models\ObjectiveActivity;
use Modules\Performance\Domain\Models\PerformanceCycle;
use Modules\Performance\Domain\Models\PerformanceObjective;
use Modules\Performance\Domain\Models\PerformancePlan;
use Modules\Performance\Domain\Models\StrategicGoal;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * ADR 0019: the WLMS entry forms and their weight rules.
 */
beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    $type = OrganizationType::query()->create(['code' => 'SH-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SH-ORG', 'name_en' => 'Bureau', 'name_am' => 'ቢሮ', 'status' => 'active']);
    $this->other = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SH-OTHER', 'name_en' => 'Other', 'status' => 'active']);
    $role = Role::findOrCreate('Strategy Hierarchy Planner', 'web');
    foreach (array_column(require database_path('seeders/data/performance-permissions.php'), 'name') as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->planner = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $this->planner->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->cycle = PerformanceCycle::query()->create(['code' => 'SH-FY', 'name_en' => 'FY', 'organization_id' => $this->org->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->service = app(StrategyHierarchyService::class);
});

function hierarchyMain($test, string $weight = '60', array $strategies = [['title' => 'Digital services', 'weight_percent' => '40'], ['title' => 'Staff skills', 'weight_percent' => '20']]): MainStrategy
{
    return $test->service->saveMainStrategy(null, [
        'cycle_id' => $test->cycle->id, 'organization_id' => $test->org->id,
        'title' => 'Service delivery', 'weight_percent' => $weight, 'strategies' => $strategies,
    ], $test->planner);
}

/** @return array<int, array<string, mixed>> objectives worth 25 + 15 = 40 points, each with two activities */
function hierarchyObjectives(): array
{
    return [
        ['title' => 'Put services online', 'weight_percent' => '25', 'activities' => [
            ['title' => 'Choose services', 'weight_percent' => '30', 'time_minutes' => 120],
            ['title' => 'Build forms', 'weight_percent' => '70', 'time_minutes' => null],
        ]],
        ['title' => 'Cut waiting time', 'weight_percent' => '15', 'activities' => [
            ['title' => 'Measure queues', 'weight_percent' => '50'],
            ['title' => 'Add counters', 'weight_percent' => '50'],
        ]],
    ];
}

it('requires the strategies to equal the main strategy weight and the main strategies to stay within 100', function (): void {
    expect(fn () => hierarchyMain($this, '60', [['title' => 'A', 'weight_percent' => '40'], ['title' => 'B', 'weight_percent' => '19.99']]))
        ->toThrow(ValidationException::class);

    $main = hierarchyMain($this);
    expect($main->strategies()->pluck('weight_percent')->all())->toBe(['40.0000', '20.0000'])
        ->and($main->strategies()->pluck('sort_order')->all())->toBe([1, 2]);

    expect(fn () => hierarchyMain($this, '40.01', [['title' => 'C', 'weight_percent' => '40.01']]))->toThrow(ValidationException::class);
    hierarchyMain($this, '40', [['title' => 'C', 'weight_percent' => '40']]);
    expect(MainStrategy::query()->sum('weight_percent'))->toEqual(100);
});

it('registers a goal with the strategy weight and writes its objectives and activities into the organization plan', function (): void {
    $strategy = hierarchyMain($this)->strategies()->first();

    $goal = $this->service->saveGoal(null, ['strategy_id' => $strategy->id, 'description' => 'Why', 'objectives' => hierarchyObjectives()], $this->planner);

    expect($goal->strategy_id)->toBe($strategy->id)
        ->and($goal->code)->toBe('SG-1-1')
        ->and($goal->weight_percent)->toBe('40.0000')
        ->and($goal->name_am)->toBe('Digital services')
        ->and($goal->status->value)->toBe('DRAFT');
    $plan = PerformancePlan::query()->sole();
    expect($plan->plan_type->value)->toBe('ORGANIZATION')->and($plan->status->value)->toBe('DRAFT');
    $objectives = $plan->objectives()->orderBy('sort_order')->get();
    expect($objectives->pluck('code')->all())->toBe(['SG-1-1-O1', 'SG-1-1-O2'])
        ->and($objectives->pluck('absolute_weight_percent')->all())->toBe(['25.0000', '15.0000'])
        ->and($objectives->pluck('strategic_goal_id')->unique()->all())->toBe([$goal->id]);
    expect($objectives->first()->activities->pluck('weight_percent')->all())->toBe(['30.0000', '70.0000'])
        ->and($objectives->first()->activities->first()->time_minutes)->toBe(120);
    // The existing readiness check sees the goal's objectives as complete.
    expect(app(StrategicPlanningService::class)->readiness($goal)['objective_total'])->toBe('40.0000');
});

it('rejects objectives that do not add up to the strategy weight and activities that do not add up to 100', function (): void {
    $strategy = hierarchyMain($this)->strategies()->first();
    $objectives = hierarchyObjectives();
    $objectives[1]['weight_percent'] = '14';
    expect(fn () => $this->service->saveGoal(null, ['strategy_id' => $strategy->id, 'objectives' => $objectives], $this->planner))->toThrow(ValidationException::class);

    $objectives = hierarchyObjectives();
    $objectives[0]['activities'][1]['weight_percent'] = '69';
    expect(fn () => $this->service->saveGoal(null, ['strategy_id' => $strategy->id, 'objectives' => $objectives], $this->planner))->toThrow(ValidationException::class);

    expect(StrategicGoal::query()->count())->toBe(0)->and(PerformanceObjective::query()->count())->toBe(0);
});

it('allows one goal per strategy', function (): void {
    $strategy = hierarchyMain($this)->strategies()->first();
    $this->service->saveGoal(null, ['strategy_id' => $strategy->id, 'objectives' => hierarchyObjectives()], $this->planner);

    expect(fn () => $this->service->saveGoal(null, ['strategy_id' => $strategy->id, 'objectives' => hierarchyObjectives()], $this->planner))->toThrow(ValidationException::class);
});

it('edits objectives and activities in place, keeping their ids', function (): void {
    $strategy = hierarchyMain($this)->strategies()->first();
    $goal = $this->service->saveGoal(null, ['strategy_id' => $strategy->id, 'objectives' => hierarchyObjectives()], $this->planner);
    $first = PerformanceObjective::query()->where('code', 'SG-1-1-O1')->sole();
    $kept = $first->activities()->first();

    $this->service->saveGoal($goal, ['objectives' => [[
        'id' => $first->id, 'title' => 'Put every service online', 'weight_percent' => '40', 'activities' => [
            ['id' => $kept->id, 'title' => 'Choose services', 'weight_percent' => '50', 'time_minutes' => 60],
            ['title' => 'Train staff', 'weight_percent' => '50'],
        ],
    ]]], $this->planner);

    expect(PerformanceObjective::query()->pluck('id')->all())->toBe([$first->id])
        ->and($first->fresh()->title_am)->toBe('Put every service online')
        ->and($first->fresh()->weight)->toBe('40.0000');
    expect(ObjectiveActivity::query()->orderBy('sort_order')->pluck('title')->all())->toBe(['Choose services', 'Train staff'])
        ->and($kept->fresh()->time_minutes)->toBe(60);
});

it('lets a draft goal follow its strategy and locks the strategy once the goal leaves draft', function (): void {
    $main = hierarchyMain($this);
    [$digital, $skills] = $main->strategies()->get()->all();
    $goal = $this->service->saveGoal(null, ['strategy_id' => $digital->id, 'objectives' => hierarchyObjectives()], $this->planner);

    $this->service->saveMainStrategy($main, ['title' => 'Service delivery', 'weight_percent' => '60', 'strategies' => [
        ['id' => $digital->id, 'title' => 'Online services', 'weight_percent' => '35'],
        ['id' => $skills->id, 'title' => 'Staff skills', 'weight_percent' => '25'],
    ]], $this->planner);
    expect($goal->fresh()->weight_percent)->toBe('35.0000')->and($goal->fresh()->name_am)->toBe('Online services');

    $goal->fresh()->forceFill(['status' => 'UNDER_REVIEW'])->save();
    expect(fn () => $this->service->saveMainStrategy($main->fresh(), ['title' => 'Service delivery', 'weight_percent' => '60', 'strategies' => [
        ['id' => $digital->id, 'title' => 'Online services', 'weight_percent' => '30'],
        ['id' => $skills->id, 'title' => 'Staff skills', 'weight_percent' => '30'],
    ]], $this->planner))->toThrow(ValidationException::class);
    expect(fn () => $this->service->saveMainStrategy($main->fresh(), ['title' => 'Service delivery', 'weight_percent' => '60', 'strategies' => [
        ['id' => $skills->id, 'title' => 'Staff skills', 'weight_percent' => '60'],
    ]], $this->planner))->toThrow(ValidationException::class);
    expect(fn () => $this->service->deleteMainStrategy($main->fresh(), $this->planner))->toThrow(ValidationException::class);
    expect($digital->fresh()->weight_percent)->toBe('35.0000');
});

it('validates the nested goal form and shows the overview with its totals', function (): void {
    $as = fn () => $this->actingAs($this->planner)->from('/performance/strategy');
    $as()->post(route('performance.strategy.main.store'), [
        'cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'title' => 'Service delivery', 'weight_percent' => '60',
        'strategies' => [['title' => 'Digital services', 'weight_percent' => '40'], ['title' => 'Staff skills', 'weight_percent' => '20']],
    ])->assertSessionHasNoErrors();
    $strategy = MainStrategy::query()->sole()->strategies()->first();

    $objectives = hierarchyObjectives();
    array_pop($objectives[1]['activities']);
    $as()->post(route('performance.strategy.goals.store'), ['strategy_id' => $strategy->id, 'objectives' => $objectives])
        ->assertSessionHasErrors('objectives.1.activities');

    $as()->post(route('performance.strategy.goals.store'), ['strategy_id' => $strategy->id, 'objectives' => hierarchyObjectives()])->assertSessionHasNoErrors();
    $goal = StrategicGoal::query()->sole();

    $this->actingAs($this->planner)->get(route('performance.strategy.index', ['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Performance/Strategy/Index')
            ->where('summary.main_count', 1)->where('summary.strategy_count', 2)->where('summary.goal_count', 1)
            ->where('summary.objective_count', 2)->where('summary.activity_count', 4)
            ->where('summary.main_total', '60.00')->where('summary.remaining', '40.00')
            ->where('mains.0.strategies.0.number', '1.1')->where('mains.0.strategies.0.share_of_main', '66.67')
            ->where('mains.0.strategies.0.goal.objectives_total', '40.00'));

    $this->actingAs($this->planner)->get(route('performance.strategy.goals.edit', $goal))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Performance/Strategy/GoalForm')
            ->where('editable', true)->has('goal.objectives', 2)->has('goal.objectives.0.activities', 2));
});

it('keeps other organizations out', function (): void {
    $this->actingAs($this->planner)->get(route('performance.strategy.index', ['organization_id' => $this->other->id]))->assertForbidden();
    $this->actingAs($this->planner)->get(route('performance.strategy.main.create', ['organization_id' => $this->other->id, 'cycle_id' => $this->cycle->id]))->assertForbidden();
    expect(fn () => $this->service->saveMainStrategy(null, [
        'cycle_id' => $this->cycle->id, 'organization_id' => $this->other->id, 'title' => 'X', 'weight_percent' => '10',
        'strategies' => [['title' => 'Y', 'weight_percent' => '10']],
    ], $this->planner))->toThrow(AuthorizationException::class);
});

it('shows the goal form read-only to a goal author without organization plan oversight', function (): void {
    $strategy = hierarchyMain($this)->strategies()->first();
    $role = Role::findOrCreate('Strategy Hierarchy Officer', 'web');
    foreach (['strategic_goals.view', 'strategic_goals.create', 'strategic_goals.update', 'performance_plans.create', 'performance_objectives.manage'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $officer = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $officer->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);

    $this->actingAs($officer)->get(route('performance.strategy.index', ['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id]))
        ->assertInertia(fn (Assert $page) => $page->where('can.create', true)->where('can.plan', false));
    $this->actingAs($officer)->get(route('performance.strategy.goals.create', ['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'strategy_id' => $strategy->id]))
        ->assertInertia(fn (Assert $page) => $page->where('editable', false)->where('canPlan', false));
    expect(fn () => $this->service->saveGoal(null, ['strategy_id' => $strategy->id, 'objectives' => hierarchyObjectives()], $officer))->toThrow(AuthorizationException::class);
    expect(StrategicGoal::query()->count())->toBe(0);
});
