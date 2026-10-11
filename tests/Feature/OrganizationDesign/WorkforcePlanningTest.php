<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\EmployeeAssignment;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionRequirement;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\UnitDesignProposal;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkforcePlanningMethodology;
use App\Services\OrganizationDesign\ManpowerCalculationService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 7: methodologies with
 * whitelisted calculation strategies and schema-validated parameters,
 * manpower plans and lines calculated with DECIMAL maths and a trace,
 * position requirements, and their implementation as positions and
 * establishments — never assignments. Brief §45–57, 80; tests 49–58.
 */

beforeEach(function (): void {
    foreach ([
        'workforce-planning.viewAny', 'workforce-planning.view', 'workforce-planning.create', 'workforce-planning.update', 'workforce-planning.review', 'workforce-planning.approve',
        'workforce-planning.implement', 'methodologies.viewAny', 'methodologies.manage', 'methodologies.approve',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Workforce Planner', 'web')->syncPermissions(['workforce-planning.viewAny', 'workforce-planning.view', 'workforce-planning.create', 'workforce-planning.update', 'workforce-planning.review', 'methodologies.viewAny']);
    Role::findOrCreate('Workforce Approver', 'web')->syncPermissions(['workforce-planning.viewAny', 'workforce-planning.view', 'workforce-planning.review', 'workforce-planning.approve', 'workforce-planning.implement']);
    Role::findOrCreate('Methodology Steward', 'web')->syncPermissions(['methodologies.viewAny', 'methodologies.manage']);
    Role::findOrCreate('Methodology Approver', 'web')->syncPermissions(['methodologies.viewAny', 'methodologies.approve']);
});

function wpOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'WP-BUREAU'], ['name_en' => 'WP Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'WP-'.$suffix.'-'.uniqid(), 'name_en' => 'WP Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function wpUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function wpUnit(Organization $organization, string $code): OrganizationUnit
{
    $type = OrganizationUnitType::query()->firstOrCreate(['code' => 'WP-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);

    return OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $type->id, 'code' => $code, 'name_en' => 'Unit '.$code, 'unit_type' => 'directorate', 'status' => 'active']);
}

function wpOccupation(string $code = '2411'): Occupation
{
    // isco_code is unique on occupations; each fixture gets its own.
    static $n = 0;
    $isco = $code.str_pad((string) (++$n), 3, '0', STR_PAD_LEFT);

    return Occupation::query()->create(['code' => 'WP-'.$isco.'-'.substr(uniqid(), -3), 'isco_code' => $isco, 'name_en' => 'Accountant', 'is_active' => true]);
}

/** A published methodology written straight into the table. */
function wpMethodology(string $type, array $parameters, ?string $rounding = 'ceil', string $code = 'WLM'): WorkforcePlanningMethodology
{
    return WorkforcePlanningMethodology::query()->create([
        'code' => $code.'-'.substr(uniqid(), -4), 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'name_en' => 'Methodology '.$type, 'calculation_type' => $type,
        'parameters' => $parameters, 'rounding_rule' => $rounding, 'status' => OrganizationDesignStatus::Published, 'effective_from' => '2024-01-01',
    ]);
}

function wpWorkloadParams(): array
{
    return ['annual_working_hours' => '1760', 'productive_time_percent' => '85', 'leave_allowance_percent' => '10'];
}

/** @return array<string, mixed> */
function wpPlanPayload(Organization $organization, WorkforcePlanningMethodology $methodology, array $overrides = []): array
{
    return ['organization_id' => $organization->id, 'title_en' => 'Manpower plan 2027', 'methodology_id' => $methodology->id, 'planning_period_start' => '2027-01-01', 'planning_period_end' => '2027-12-31', ...$overrides];
}

function wpPlan(Organization $organization, User $planner, WorkforcePlanningMethodology $methodology, array $overrides = []): ManpowerPlan
{
    test()->actingAs($planner)->post(route('organization-design.manpower-plans.store'), wpPlanPayload($organization, $methodology, $overrides))->assertRedirect();

    return ManpowerPlan::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
}

function wpLine(User $planner, ManpowerPlan $plan, array $payload): ManpowerPlanLine
{
    test()->actingAs($planner)->post(route('organization-design.manpower-plans.lines.store', $plan), $payload)->assertRedirect();

    return ManpowerPlanLine::query()->where('plan_id', $plan->id)->latest('id')->firstOrFail();
}

function wpTask(Organization $organization): ProcessTask
{
    $service = Service::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'SRV-'.substr(uniqid(), -4), 'name_en' => 'Service', 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published]);
    $process = Process::query()->create(['organization_id' => $organization->id, 'service_id' => $service->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'PRC-'.substr(uniqid(), -4), 'name_en' => 'Process', 'process_type' => 'core', 'effective_from' => '2022-01-01', 'status' => OrganizationDesignStatus::Published]);
    $activity = ProcessActivity::query()->create(['process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'A-1', 'name_en' => 'Activity', 'path' => '', 'depth' => 0]);
    $activity->forceFill(['path' => $activity->id])->save();

    return ProcessTask::query()->create(['activity_id' => $activity->id, 'process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'T-1', 'name_en' => 'Task']);
}

// 49 ─────────────────────────────────────────────────────────────────────────
test('a methodology uses a whitelisted strategy with parameters validated against its schema; nothing executable, published by someone else', function (): void {
    $steward = User::factory()->create();
    $steward->assignRole('Methodology Steward');
    $approver = User::factory()->create();
    $approver->assignRole('Methodology Approver');
    $base = ['code' => 'WLM-1', 'name_en' => 'Workload standard time', 'name_am' => 'የሥራ ጫና', 'calculation_type' => 'WORKLOAD_STANDARD_TIME', 'rounding_rule' => 'ceil', 'effective_from' => '2026-01-01'];

    $this->actingAs($steward)->from('/')->post(route('organization-design.methodologies.store'), [...$base, 'calculation_type' => 'CUSTOM_FORMULA'])->assertSessionHasErrors('calculation_type');
    $this->actingAs($steward)->from('/')->post(route('organization-design.methodologies.store'), [...$base, 'parameters' => ['annual_working_hours' => '1760 * 0.9', 'productive_time_percent' => '85']])->assertSessionHasErrors('parameters.annual_working_hours');
    $this->actingAs($steward)->from('/')->post(route('organization-design.methodologies.store'), [...$base, 'parameters' => ['annual_working_hours' => '1760', 'productive_time_percent' => '150']])->assertSessionHasErrors('parameters.productive_time_percent');
    $this->actingAs($steward)->from('/')->post(route('organization-design.methodologies.store'), [...$base, 'parameters' => ['annual_working_hours' => '1760', 'productive_time_percent' => '85', 'automation_factor' => '0.5']])->assertSessionHasErrors('parameters.automation_factor');

    // A draft may be saved incomplete; it cannot be published until every parameter the strategy uses is set (D-6).
    $this->actingAs($steward)->post(route('organization-design.methodologies.store'), [...$base, 'parameters' => ['annual_working_hours' => '1760']])->assertRedirect();
    $methodology = WorkforcePlanningMethodology::query()->where('code', 'WLM-1')->firstOrFail();
    expect($methodology->status)->toBe(OrganizationDesignStatus::Draft);
    $this->actingAs($approver)->from('/')->post(route('organization-design.methodologies.publish', $methodology))->assertSessionHasErrors('parameters');

    $this->actingAs($steward)->put(route('organization-design.methodologies.update', $methodology), ['parameters' => wpWorkloadParams()])->assertRedirect();
    $this->actingAs($steward)->post(route('organization-design.methodologies.publish', $methodology))->assertForbidden();
    $this->actingAs($approver)->post(route('organization-design.methodologies.publish', $methodology))->assertRedirect();
    expect($methodology->fresh()->status)->toBe(OrganizationDesignStatus::Published);

    // Published versions are immutable; a planner reads the catalog but never writes it.
    $this->actingAs($steward)->from('/')->put(route('organization-design.methodologies.update', $methodology), ['name_en' => 'x'])->assertSessionHasErrors('status');
    $planner = wpUser(wpOrganization('A'), 'Workforce Planner');
    $this->actingAs($planner)->get(route('organization-design.methodologies.index'))->assertOk();
    $this->actingAs($planner)->post(route('organization-design.methodologies.store'), $base)->assertForbidden();
});

// 50 ─────────────────────────────────────────────────────────────────────────
test('workload × standard time is computed in decimals with a trace, and the rounding rule turns FTE into headcount', function (): void {
    $calculator = app(ManpowerCalculationService::class);
    $inputs = ['annual_volume' => '12000', 'standard_time_minutes' => '30'];

    $result = $calculator->calculate('WORKLOAD_STANDARD_TIME', wpWorkloadParams(), $inputs, 'ceil');
    expect($result['calculated_fte'])->toBe('4.4563')
        ->and($result['recommended_headcount'])->toBe(5)
        ->and($result['trace']['required_hours'])->toBe('6000.0000')
        ->and($result['trace']['available_hours_per_fte'])->toBe('1346.4000')
        ->and($result['trace']['formula'])->toBeString();

    expect($calculator->calculate('WORKLOAD_STANDARD_TIME', wpWorkloadParams(), $inputs, 'half_up')['recommended_headcount'])->toBe(4)
        ->and($calculator->calculate('WORKLOAD_STANDARD_TIME', wpWorkloadParams(), $inputs, 'floor')['recommended_headcount'])->toBe(4)
        ->and($calculator->calculate('WORKLOAD_STANDARD_TIME', wpWorkloadParams(), $inputs, 'manual')['recommended_headcount'])->toBeNull()
        ->and($calculator->calculate('WORKLOAD_STANDARD_TIME', wpWorkloadParams(), $inputs, null)['recommended_headcount'])->toBeNull();

    // Half-up at exactly .5 rounds up; floats never enter.
    $exact = $calculator->calculate('WORKLOAD_STANDARD_TIME', ['annual_working_hours' => '1000', 'productive_time_percent' => '100', 'leave_allowance_percent' => '0'], ['annual_volume' => '2500', 'standard_time_minutes' => '60'], 'half_up');
    expect($exact['calculated_fte'])->toBe('2.5000')->and($exact['recommended_headcount'])->toBe(3);

    expect(fn () => $calculator->calculate('WORKLOAD_STANDARD_TIME', wpWorkloadParams(), ['annual_volume' => '10'], 'ceil'))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => $calculator->calculate('WORKLOAD_STANDARD_TIME', ['annual_working_hours' => '0', 'productive_time_percent' => '85', 'leave_allowance_percent' => '0'], $inputs, 'ceil'))->toThrow(\Illuminate\Validation\ValidationException::class);
});

// 51 ─────────────────────────────────────────────────────────────────────────
test('ratio, fixed-minimum and span-of-control strategies compute from their own parameters and inputs', function (): void {
    $calculator = app(ManpowerCalculationService::class);

    $ratio = $calculator->calculate('RATIO', ['units_per_position' => '250'], ['driver_quantity' => '1600'], 'ceil');
    expect($ratio['calculated_fte'])->toBe('6.4000')->and($ratio['recommended_headcount'])->toBe(7);

    $fixed = $calculator->calculate('FIXED_MINIMUM', ['minimum_positions' => '3'], [], 'ceil');
    expect($fixed['calculated_fte'])->toBe('3.0000')->and($fixed['recommended_headcount'])->toBe(3);

    $span = $calculator->calculate('SPAN_OF_CONTROL', ['span' => '8'], ['subordinate_count' => '20'], 'ceil');
    expect($span['calculated_fte'])->toBe('2.5000')->and($span['recommended_headcount'])->toBe(3);

    expect(fn () => $calculator->calculate('RATIO', ['units_per_position' => '0'], ['driver_quantity' => '10'], 'ceil'))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => $calculator->calculate('SOMETHING_ELSE', [], [], 'ceil'))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($calculator->strategies())->toBe(['WORKLOAD_STANDARD_TIME', 'RATIO', 'FIXED_MINIMUM', 'SPAN_OF_CONTROL']);
});

// 52 ─────────────────────────────────────────────────────────────────────────
test('a line on a master task takes its standard time from the approved standard in force and records which version it used', function (): void {
    $organization = wpOrganization('B');
    $planner = wpUser($organization, 'Workforce Planner');
    $methodology = wpMethodology('WORKLOAD_STANDARD_TIME', wpWorkloadParams());
    $unit = wpUnit($organization, 'U-1');
    $occupation = wpOccupation();
    $task = wpTask($organization);
    $v1 = PositionServiceTaskStandard::query()->create(['master_task_id' => $task->id, 'organization_id' => $organization->id, 'version_no' => 1, 'status' => 'approved', 'planned_time_minutes' => '30', 'effective_from' => '2024-01-01']);
    $plan = wpPlan($organization, $planner, $methodology);

    $line = wpLine($planner, $plan, ['organization_unit_id' => $unit->id, 'task_id' => $task->id, 'occupation_id' => $occupation->id, 'inputs' => ['annual_volume' => '12000']]);
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.calculate', $line))->assertRedirect();
    $line->refresh();
    expect($line->calculated_fte)->toBe('4.4563')
        ->and($line->standard_id)->toBe($v1->id)
        ->and($line->methodology_version_no)->toBe(1)
        ->and($line->recommended_headcount)->toBe(5)
        ->and($line->calculation_trace['inputs']['standard_time_minutes'])->toBe('30.0000');

    // A newer standard does not move the line until it is recalculated.
    $v1->forceFill(['effective_to' => '2026-06-30'])->save();
    $v2 = PositionServiceTaskStandard::query()->create(['master_task_id' => $task->id, 'organization_id' => $organization->id, 'version_no' => 2, 'status' => 'approved', 'planned_time_minutes' => '20', 'effective_from' => '2026-07-01']);
    expect($line->fresh()->calculated_fte)->toBe('4.4563');
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.calculate', $line))->assertRedirect();
    expect($line->fresh()->standard_id)->toBe($v2->id)->and($line->fresh()->calculated_fte)->toBe('2.9709');

    // Without a standard and without a manual minute input the line cannot be calculated.
    $bare = wpLine($planner, $plan, ['organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => ['annual_volume' => '100']]);
    $this->actingAs($planner)->from('/')->post(route('organization-design.manpower-plans.lines.calculate', $bare))->assertSessionHasErrors('inputs.standard_time_minutes');
});

// 53 ─────────────────────────────────────────────────────────────────────────
test('a plan is reviewed and approved by someone other than its preparer, and only when every line has a headcount', function (): void {
    $organization = wpOrganization('C');
    $planner = wpUser($organization, 'Workforce Planner');
    $approver = wpUser($organization, 'Workforce Approver');
    $manual = wpMethodology('FIXED_MINIMUM', ['minimum_positions' => '2'], 'manual', 'FIX');
    $unit = wpUnit($organization, 'U-1');
    $occupation = wpOccupation();
    $plan = wpPlan($organization, $planner, $manual);
    expect($plan->reference_number)->toMatch('/^MPP-\d{4}-\d{4}$/')->and($plan->status)->toBe(OrganizationDesignStatus::Draft);

    $line = wpLine($planner, $plan, ['organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => []]);
    $this->actingAs($planner)->from('/')->post(route('organization-design.manpower-plans.submit', $plan))->assertSessionHasErrors('validation');

    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.calculate', $line))->assertRedirect();
    expect($line->fresh()->calculated_fte)->toBe('2.0000')->and($line->fresh()->recommended_headcount)->toBeNull();
    // Manual rounding: the planner must decide, with a reason.
    $this->actingAs($planner)->from('/')->post(route('organization-design.manpower-plans.submit', $plan))->assertSessionHasErrors('validation');
    $this->actingAs($planner)->from('/')->put(route('organization-design.manpower-plans.lines.update', $line), ['approved_headcount' => 2])->assertSessionHasErrors('adjustment_reason');
    $this->actingAs($planner)->put(route('organization-design.manpower-plans.lines.update', $line), ['approved_headcount' => 2, 'adjustment_reason' => 'Two shifts'])->assertRedirect();
    expect($line->fresh()->approved_headcount)->toBe(2)->and($line->fresh()->adjusted_by)->toBe($planner->id);

    $this->actingAs($planner)->post(route('organization-design.manpower-plans.submit', $plan))->assertRedirect();
    expect($plan->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.approve', $plan))->assertForbidden();
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.return', $plan), ['reason' => 'Check unit'])->assertRedirect();
    expect($plan->fresh()->status)->toBe(OrganizationDesignStatus::Draft);
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.submit', $plan))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.approve', $plan))->assertRedirect();
    expect($plan->fresh()->status)->toBe(OrganizationDesignStatus::Approved)->and($plan->fresh()->approved_by)->toBe($approver->id);

    // Approved plans and their lines are immutable.
    $this->actingAs($planner)->from('/')->put(route('organization-design.manpower-plans.lines.update', $line), ['approved_headcount' => 3, 'adjustment_reason' => 'x'])->assertSessionHasErrors('status');
    $this->actingAs($planner)->from('/')->post(route('organization-design.manpower-plans.lines.store', $plan), ['organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => []])->assertSessionHasErrors('status');
});

// 54 ─────────────────────────────────────────────────────────────────────────
test('requirements are rolled up from approved lines by unit and occupation, and implemented as positions with establishments — never assignments', function (): void {
    $organization = wpOrganization('D');
    $planner = wpUser($organization, 'Workforce Planner');
    $approver = wpUser($organization, 'Workforce Approver');
    $methodology = wpMethodology('RATIO', ['units_per_position' => '100'], 'ceil', 'RAT');
    $unit = wpUnit($organization, 'U-1');
    $other = wpUnit($organization, 'U-2');
    $accountant = wpOccupation('2411');
    $clerk = wpOccupation('4110');
    $plan = wpPlan($organization, $planner, $methodology);
    foreach ([[$unit, $accountant, '150'], [$unit, $accountant, '120'], [$unit, $clerk, '50'], [$other, $accountant, '10']] as [$u, $o, $driver]) {
        $line = wpLine($planner, $plan, ['organization_unit_id' => $u->id, 'occupation_id' => $o->id, 'inputs' => ['driver_quantity' => $driver]]);
        $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.calculate', $line))->assertRedirect();
    }
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.submit', $plan))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.approve', $plan))->assertRedirect();

    $requirements = PositionRequirement::query()->where('plan_id', $plan->id)->orderBy('organization_unit_id')->orderBy('occupation_id')->get();
    expect($requirements)->toHaveCount(3);
    $acc = $requirements->first(fn ($r) => $r->organization_unit_id === $unit->id && $r->occupation_id === $accountant->id);
    expect($acc->required_fte)->toBe('2.7000')->and($acc->approved_headcount)->toBe(4)->and($acc->status)->toBe('approved');
    $cl = $requirements->first(fn ($r) => $r->occupation_id === $clerk->id);
    expect($cl->required_fte)->toBe('0.5000')->and($cl->approved_headcount)->toBe(1);

    $this->actingAs($planner)->post(route('organization-design.manpower-plans.implement', $plan))->assertForbidden();
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.implement', $plan))->assertRedirect();
    $acc->refresh();
    expect($acc->status)->toBe('implemented')->and($acc->position_id)->not->toBeNull();
    $position = Position::query()->findOrFail($acc->position_id);
    expect($position->organization_unit_id)->toBe($unit->id)->and($position->occupation_id)->toBe($accountant->id)->and($position->is_active)->toBeTrue()
        ->and($position->job_position_code)->toStartWith($plan->reference_number);
    $establishment = PositionEstablishment::query()->where('position_id', $position->id)->firstOrFail();
    expect($establishment->approved_slots)->toBe(4)->and($establishment->status instanceof BackedEnum ? $establishment->status->value : $establishment->status)->toBe('approved')->and($establishment->approval_reference)->toBe($plan->reference_number);
    expect(Position::query()->where('organization_id', $organization->id)->count())->toBe(3)
        ->and(EmployeeAssignment::query()->where('organization_id', $organization->id)->count())->toBe(0);

    // Running again creates nothing new.
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.implement', $plan))->assertRedirect();
    expect(Position::query()->where('organization_id', $organization->id)->count())->toBe(3)->and(PositionEstablishment::query()->where('organization_id', $organization->id)->count())->toBe(3);
});

// 55 ─────────────────────────────────────────────────────────────────────────
test('a scoped planner cannot read or write another organization\'s plan, and sees only own plans', function (): void {
    $mine = wpOrganization('E');
    $theirs = wpOrganization('E2');
    $planner = wpUser($mine, 'Workforce Planner');
    $foreignPlanner = wpUser($theirs, 'Workforce Planner');
    $methodology = wpMethodology('FIXED_MINIMUM', ['minimum_positions' => '1'], 'ceil', 'FIX');
    $foreignPlan = wpPlan($theirs, $foreignPlanner, $methodology);
    $foreignUnit = wpUnit($theirs, 'U-X');
    $own = wpPlan($mine, $planner, $methodology, ['title_en' => 'Mine']);

    $this->actingAs($planner)->post(route('organization-design.manpower-plans.store'), wpPlanPayload($theirs, $methodology))->assertForbidden();
    $this->actingAs($planner)->get(route('organization-design.manpower-plans.show', $foreignPlan))->assertForbidden();
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.store', $foreignPlan), ['organization_unit_id' => $foreignUnit->id, 'occupation_id' => wpOccupation()->id, 'inputs' => []])->assertForbidden();
    // A unit of another organization cannot be planned on an own plan either.
    $this->actingAs($planner)->from('/')->post(route('organization-design.manpower-plans.lines.store', $own), ['organization_unit_id' => $foreignUnit->id, 'occupation_id' => wpOccupation()->id, 'inputs' => []])->assertSessionHasErrors('organization_unit_id');
    $this->actingAs($planner)->get(route('organization-design.manpower-plans.index'))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/ManpowerPlans/Index')->where('plans.total', 1)->where('plans.data.0.title_en', 'Mine'));
});

// 56 ─────────────────────────────────────────────────────────────────────────
test('a line may plan a proposed unit of an approved study; its requirement waits until the unit exists', function (): void {
    $organization = wpOrganization('F');
    $planner = wpUser($organization, 'Workforce Planner');
    $approver = wpUser($organization, 'Workforce Approver');
    $methodology = wpMethodology('FIXED_MINIMUM', ['minimum_positions' => '2'], 'ceil', 'FIX');
    $study = OrganizationDesignStudy::query()->create(['organization_id' => $organization->id, 'reference_number' => 'ODS-T-'.substr(uniqid(), -5), 'title_en' => 'Study', 'study_type' => 'restructure', 'status' => OrganizationDesignStatus::Approved, 'prepared_by' => $planner->id]);
    $proposal = UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $organization->id, 'proposal_type' => 'create', 'name_en' => 'Proposed unit', 'status' => 'accepted']);
    $occupation = wpOccupation();
    $plan = wpPlan($organization, $planner, $methodology, ['study_id' => $study->id]);

    $line = wpLine($planner, $plan, ['unit_design_proposal_id' => $proposal->id, 'occupation_id' => $occupation->id, 'inputs' => []]);
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.calculate', $line))->assertRedirect();
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.submit', $plan))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.approve', $plan))->assertRedirect();
    $requirement = PositionRequirement::query()->where('plan_id', $plan->id)->firstOrFail();
    expect($requirement->unit_design_proposal_id)->toBe($proposal->id)->and($requirement->organization_unit_id)->toBeNull();

    $this->actingAs($approver)->post(route('organization-design.manpower-plans.implement', $plan))->assertRedirect();
    expect($requirement->fresh()->status)->toBe('approved')->and(Position::query()->where('organization_id', $organization->id)->count())->toBe(0);

    // Once the pipeline has implemented the proposal, implementation lands on the resulting unit.
    $unit = wpUnit($organization, 'U-NEW');
    $proposal->forceFill(['resulting_unit_id' => $unit->id, 'status' => 'implemented'])->save();
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.implement', $plan))->assertRedirect();
    expect($requirement->fresh()->status)->toBe('implemented')->and($requirement->fresh()->organization_unit_id)->toBe($unit->id)
        ->and(Position::query()->where('organization_unit_id', $unit->id)->count())->toBe(1);
});

// 57 ─────────────────────────────────────────────────────────────────────────
test('a published methodology gets a new version for a parameter change, and lines keep the version they were calculated with', function (): void {
    $organization = wpOrganization('G');
    $planner = wpUser($organization, 'Workforce Planner');
    $steward = User::factory()->create();
    $steward->assignRole('Methodology Steward');
    $approver = User::factory()->create();
    $approver->assignRole('Methodology Approver');
    $methodology = wpMethodology('RATIO', ['units_per_position' => '100'], 'ceil', 'RAT');
    $unit = wpUnit($organization, 'U-1');
    $plan = wpPlan($organization, $planner, $methodology);
    $line = wpLine($planner, $plan, ['organization_unit_id' => $unit->id, 'occupation_id' => wpOccupation()->id, 'inputs' => ['driver_quantity' => '250']]);
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.calculate', $line))->assertRedirect();
    expect($line->fresh()->calculated_fte)->toBe('2.5000')->and($line->fresh()->methodology_version_no)->toBe(1);

    $this->actingAs($steward)->post(route('organization-design.methodologies.versions.store', $methodology), ['parameters' => ['units_per_position' => '50'], 'effective_from' => '2027-01-01'])->assertRedirect();
    $v2 = WorkforcePlanningMethodology::query()->where('lineage_key', $methodology->lineage_key)->where('version_no', 2)->firstOrFail();
    $this->actingAs($approver)->post(route('organization-design.methodologies.publish', $v2))->assertRedirect();
    expect($methodology->fresh()->status)->toBe(OrganizationDesignStatus::Superseded)
        ->and($line->fresh()->calculated_fte)->toBe('2.5000')->and($line->fresh()->methodology_version_no)->toBe(1);

    // Recalculating uses the version in force on the plan's methodology lineage.
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.calculate', $line))->assertRedirect();
    expect($line->fresh()->calculated_fte)->toBe('5.0000')->and($line->fresh()->methodology_version_no)->toBe(2);
});

// 58 ─────────────────────────────────────────────────────────────────────────
test('the plan page carries lines, requirements, totals and the validation report', function (): void {
    $organization = wpOrganization('H');
    $planner = wpUser($organization, 'Workforce Planner');
    $methodology = wpMethodology('FIXED_MINIMUM', ['minimum_positions' => '3'], 'ceil', 'FIX');
    $unit = wpUnit($organization, 'U-1');
    $plan = wpPlan($organization, $planner, $methodology);
    $line = wpLine($planner, $plan, ['organization_unit_id' => $unit->id, 'occupation_id' => wpOccupation()->id, 'inputs' => []]);
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.calculate', $line))->assertRedirect();

    $this->actingAs($planner)->get(route('organization-design.manpower-plans.show', $plan))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/ManpowerPlans/Show')
            ->where('lines.0.calculated_fte', '3.0000')
            ->where('lines.0.recommended_headcount', 3)
            ->where('totals.calculated_fte', '3.0000')
            ->where('totals.headcount', 3)
            ->where('validation.ok', true)
            ->has('requirements', 0));
});
