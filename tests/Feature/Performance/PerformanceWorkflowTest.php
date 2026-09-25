<?php

declare(strict_types=1);

use App\Enums\CommitteeType;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\AppealDecision;
use App\Enums\Performance\CascadeMode;
use App\Enums\Performance\CycleStatus;
use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\ResultStatus;
use App\Enums\Performance\ReviewType;
use App\Enums\Performance\StrategicGoalStatus;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\DailyActivityItem;
use App\Models\DailyActivityLog;
use App\Models\DailyActivityReviewerAssignment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\GrievanceCommittee;
use App\Models\Kpi;
use App\Models\KpiActual;
use App\Models\KpiContribution;
use App\Models\KpiTarget;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformancePlan;
use App\Models\PerformanceRatingBand;
use App\Models\PerformanceResult;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\DevelopmentPlanService;
use App\Services\Performance\EmployeeAgreementService;
use App\Services\Performance\EmployeeScoreCalculator;
use App\Services\Performance\EpmsSettings;
use App\Services\Performance\KpiActualService;
use App\Services\Performance\PerformanceAggregationService;
use App\Services\Performance\PerformanceAppealService;
use App\Services\Performance\PerformanceCalibrationService;
use App\Services\Performance\PerformanceCycleService;
use App\Services\Performance\PerformancePlanService;
use App\Services\Performance\PerformanceResultService;
use App\Services\Performance\PerformanceReviewService;
use App\Services\Performance\PlanCascadeService;
use App\Services\Performance\StrategicPlanningService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\Performance\PerformanceRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| EPMS end to end — cascade, agreements, scoring, aggregation, reviews,
| calibration, appeals, security (numbered after the requirement list).
|--------------------------------------------------------------------------
|
| Organization A: Directorate D → Team T (position P, employees E1, E2).
| Organization B: separate (employee E3). Cycle 2026 for A.
*/

function epSetting(string $key, mixed $value): void
{
    $definition = SystemSettingsRegistry::definition(SystemSettingsRegistry::GROUP_PERFORMANCE, $key);
    SystemSetting::query()->updateOrCreate(
        ['group' => SystemSettingsRegistry::GROUP_PERFORMANCE, 'key' => $key],
        ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 'type' => $definition['type'], 'label_en' => $definition['label_en']],
    );
    app(SystemSettingsService::class)->clearCache();
}

/** @param list<string> $permissions */
function epUser(string $role, array $permissions, ?string $email = null, ?Organization $scope = null): User
{
    $roleModel = Role::findOrCreate($role, 'web');
    foreach ($permissions as $permission) {
        $roleModel->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user = User::factory()->create(array_filter(['email' => $email, 'status' => 'active']));
    $user->assignRole($roleModel);
    if ($scope !== null) {
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $scope->id, 'scope_type' => 'self', 'is_active' => true]);
    }

    return $user->fresh();
}

function epEmployee(string $number, string $email, Organization $org, OrganizationUnit $unit, Position $position, string $from = '2026-01-01', ?string $to = null): Employee
{
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => $number, 'last_name' => 'Person', 'full_name' => "{$number} Person", 'email' => $email, 'status' => 'active']);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id,
        'assignment_status' => 'active', 'effective_from' => $from, 'effective_to' => $to, 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

function epKpi(string $code, string $direction, string $aggregation, string $source = 'MANUAL', string $type = 'COUNT', array $extra = []): Kpi
{
    return Kpi::query()->create([
        'code' => $code, 'name_en' => $code, 'name_am' => $code, 'measurement_type' => $type, 'direction' => $direction,
        'aggregation_method' => $aggregation, 'data_source_type' => $source, 'frequency' => 'ANNUAL', 'is_active' => true, ...$extra,
    ]);
}

/** Submit → approve (by a second person) → publish. */
function epPublish(PerformancePlan $plan): PerformancePlan
{
    $service = app(PerformancePlanService::class);
    $service->submit($plan, test()->admin);
    $service->approve($plan->fresh(), test()->approver);

    return $service->publish($plan->fresh(), test()->admin);
}

beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));
    epSetting('require_midyear_review', false);
    epSetting('require_result_release', true);

    $type = OrganizationType::query()->create(['code' => 'EP-T', 'name_en' => 'Bureau']);
    $this->orgA = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'EP-A', 'name_en' => 'Civil Service Bureau', 'status' => 'active']);
    $this->orgB = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'EP-B', 'name_en' => 'Health Bureau', 'status' => 'active']);
    $this->directorate = OrganizationUnit::query()->create(['organization_id' => $this->orgA->id, 'code' => 'EP-D', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->team = OrganizationUnit::query()->create(['organization_id' => $this->orgA->id, 'parent_unit_id' => $this->directorate->id, 'code' => 'EP-TM', 'name_en' => 'Transfers Team', 'unit_type' => 'team', 'status' => 'active']);
    $this->team2 = OrganizationUnit::query()->create(['organization_id' => $this->orgA->id, 'parent_unit_id' => $this->directorate->id, 'code' => 'EP-TM2', 'name_en' => 'Records Team', 'unit_type' => 'team', 'status' => 'active']);
    $this->unitB = OrganizationUnit::query()->create(['organization_id' => $this->orgB->id, 'code' => 'EP-BU', 'name_en' => 'Health HR', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team->id, 'job_position_code' => 'EP-P', 'title_en' => 'HR Officer', 'is_active' => true]);
    $this->position2 = Position::query()->create(['organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'job_position_code' => 'EP-P2', 'title_en' => 'Records Officer', 'is_active' => true]);
    $this->positionB = Position::query()->create(['organization_id' => $this->orgB->id, 'organization_unit_id' => $this->unitB->id, 'job_position_code' => 'EP-PB', 'title_en' => 'Clerk', 'is_active' => true]);

    $this->e1 = epEmployee('EP-1', 'e1@ep.test', $this->orgA, $this->team, $this->position);
    $this->e2 = epEmployee('EP-2', 'e2@ep.test', $this->orgA, $this->team, $this->position);
    $this->e3 = epEmployee('EP-3', 'e3@ep.test', $this->orgB, $this->unitB, $this->positionB);

    $all = array_column(require database_path('seeders/data/performance-permissions.php'), 'name');
    $this->admin = epUser('EP Admin', $all, null, $this->orgA);
    $this->approver = epUser('EP Admin', $all, null, $this->orgA);
    $this->adminB = epUser('EP Admin', $all, null, $this->orgB);
    $this->manager = epUser(PerformanceRoles::MANAGER_ROLE, PerformanceRoles::MANAGER_PERMISSIONS);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->manager->id, 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team->id, 'include_sub_units' => true, 'is_active' => true]);
    $this->outsider = epUser(PerformanceRoles::MANAGER_ROLE, PerformanceRoles::MANAGER_PERMISSIONS);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->outsider->id, 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'include_sub_units' => true, 'is_active' => true]);
    $this->u1 = epUser(DailyActivityRoles::EMPLOYEE_ROLE, PerformanceRoles::EMPLOYEE_PERMISSIONS, 'e1@ep.test');
    $this->u2 = epUser(DailyActivityRoles::EMPLOYEE_ROLE, PerformanceRoles::EMPLOYEE_PERMISSIONS, 'e2@ep.test');

    $this->kCases = epKpi('CASES', 'HIGHER_IS_BETTER', 'SUM');
    $this->kTime = epKpi('DAYS', 'LOWER_IS_BETTER', 'WEIGHTED_AVERAGE', 'MANUAL', 'DURATION');
    $this->kErrors = epKpi('ERR', 'LOWER_IS_BETTER', 'RATIO_FROM_TOTALS', 'MANUAL', 'PERCENTAGE');

    $this->cycle = app(PerformanceCycleService::class)->create(['code' => 'FY2026', 'name_en' => 'FY 2026', 'organization_id' => $this->orgA->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $this->admin);
});

/** Organization → directorate → team → position plans, cascaded with CASES targets. */
function epCascade(): array
{
    $plans = app(PerformancePlanService::class);
    $cascade = app(PlanCascadeService::class);
    $t = test();

    $org = $plans->create(['cycle_id' => $t->cycle->id, 'plan_type' => 'ORGANIZATION', 'organization_id' => $t->orgA->id, 'title' => 'Bureau plan 2026'], $t->admin);
    $objective = $plans->addObjective($org, ['code' => 'SO1', 'title_en' => 'Faster employee services', 'weight' => 100, 'is_mandatory' => true, 'objective_type' => 'STRATEGIC'], $t->admin);
    $objective->forceFill(['is_mandatory' => true])->save();
    $plans->addTarget($objective, ['kpi_id' => $t->kCases->id, 'target_value' => 1000, 'weight' => 100], $t->admin);
    epPublish($org);

    $dir = $plans->create(['cycle_id' => $t->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $t->orgA->id, 'organization_unit_id' => $t->directorate->id, 'parent_plan_id' => $org->id, 'title' => 'HR directorate plan'], $t->admin);
    [$dirObjective] = $cascade->cascade($objective->fresh(), $dir, CascadeMode::Customize, ['title_en' => 'Reduce transfer processing time', 'weight' => 100], $t->admin);
    epPublish($dir);

    $team = $plans->create(['cycle_id' => $t->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $t->orgA->id, 'organization_unit_id' => $t->team->id, 'parent_plan_id' => $dir->id, 'title' => 'Transfers team plan'], $t->admin);
    [$teamObjective] = $cascade->cascade($dirObjective->fresh(), $team, CascadeMode::Accept, ['weight' => 100], $t->admin);
    epPublish($team);

    $position = $plans->create(['cycle_id' => $t->cycle->id, 'plan_type' => 'POSITION', 'organization_id' => $t->orgA->id, 'position_id' => $t->position->id, 'parent_plan_id' => $team->id, 'title' => 'HR officer plan'], $t->admin);
    [$posObjective] = $cascade->cascade($teamObjective->fresh(), $position, CascadeMode::Accept, ['weight' => 100], $t->admin);
    epPublish($position);

    return compact('org', 'dir', 'team', 'position', 'objective', 'dirObjective', 'teamObjective', 'posObjective');
}

/** A live agreement for $employee, approved and active. */
function epAgreement(Employee $employee): EmployeePerformanceAgreement
{
    $t = test();
    $service = app(EmployeeAgreementService::class);
    $agreement = $service->create($employee, EmployeeAssignment::query()->where('employee_id', $employee->id)->where('is_current', true)->firstOrFail(), $t->cycle->fresh(), $t->manager);
    $service->submitToEmployee($agreement, $t->manager);
    $user = User::query()->where('email', $employee->email)->firstOrFail();
    $service->acknowledge($agreement->fresh(), $user);
    $service->approve($agreement->fresh(), $t->manager);

    return $agreement->fresh();
}

function epActivateCycle(): void
{
    $cycles = app(PerformanceCycleService::class);
    foreach ([CycleStatus::Planning, CycleStatus::Cascaded, CycleStatus::Agreement, CycleStatus::Active] as $status) {
        $cycles->transition(test()->cycle->fresh(), $status, test()->admin);
    }
}

// ── Cascading (1–10) ────────────────────────────────────────────────────────

test('1-7 an objective cascades organization → unit → child unit → position → agreement with lineage preserved', function (): void {
    $w = epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);

    expect($w['org']->fresh()->status)->toBe(PlanStatus::Published)
        // 3: the directorate customized the wording, still linked.
        ->and($w['dirObjective']->title_en)->toBe('Reduce transfer processing time')
        ->and($w['dirObjective']->parent_objective_id)->toBe($w['objective']->id)
        ->and($w['teamObjective']->parent_objective_id)->toBe($w['dirObjective']->id)
        ->and($w['posObjective']->parent_objective_id)->toBe($w['teamObjective']->id)
        // 6: the agreement is built from the position plan; items total 100.
        ->and($agreement->performance_plan_id)->toBe($w['position']->id)
        ->and($agreement->items()->count())->toBe(1)
        ->and((string) $agreement->items()->first()->weight)->toBe('100.0000')
        // 7: target lineage employee item → position → team → directorate → organization.
        ->and(KpiTarget::query()->find($agreement->items()->first()->position_target_id)->parentTarget->parentTarget->parentTarget->performance_plan_id)->toBe($w['org']->id)
        ->and($agreement->status)->toBe(AgreementStatus::Active);
});

test('8 a circular or level-skipping cascade is rejected', function (): void {
    $w = epCascade();

    // Into its own plan, and skipping a level (organization → team).
    expect(fn () => app(PlanCascadeService::class)->cascade($w['objective'], $w['org'], CascadeMode::Accept, [], $this->admin))->toThrow(ValidationException::class);
    $draft = app(PerformancePlanService::class)->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'parent_plan_id' => $w['dir']->id, 'title' => 'Records'], $this->admin);
    expect(fn () => app(PlanCascadeService::class)->cascade($w['objective'], $draft, CascadeMode::Accept, [], $this->admin))->toThrow(ValidationException::class);
});

test('9 cascading into another organization\'s plan is refused (scope)', function (): void {
    $w = epCascade();
    $draft = app(PerformancePlanService::class)->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'parent_plan_id' => $w['dir']->id, 'title' => 'Records'], $this->admin);

    expect(fn () => app(PlanCascadeService::class)->cascade($w['dirObjective'], $draft, CascadeMode::Accept, [], $this->adminB))->toThrow(AuthorizationException::class);
    $this->actingAs($this->adminB)->get(route('performance.plans.show', $w['org']))->assertForbidden();
});

test('10 a published plan cannot be edited in place; a new version keeps v1 linked to existing agreements', function (): void {
    $w = epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);

    expect(fn () => app(PerformancePlanService::class)->addObjective($w['position']->fresh(), ['code' => 'X', 'title_en' => 'X', 'weight' => 0], $this->admin))->toThrow(ValidationException::class);

    $v2 = app(PerformancePlanService::class)->newVersion($w['position']->fresh(), 'Target raised mid-year', $this->admin);
    $v2Objective = $v2->objectives()->first();
    expect($v2->version_no)->toBe(2)->and($v2Objective->source_objective_id)->toBe($w['posObjective']->id);

    epPublish($v2);
    expect($w['position']->fresh()->status)->toBe(PlanStatus::Superseded)
        ->and($agreement->fresh()->performance_plan_id)->toBe($w['position']->id);
});

test('21 weights must total 100 before a plan can be submitted; mandatory parent objectives must be cascaded', function (): void {
    $plans = app(PerformancePlanService::class);
    $w = epCascade();
    $child = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'parent_plan_id' => $w['dir']->id, 'title' => 'Records'], $this->admin);
    $local = $plans->addObjective($child, ['code' => 'L1', 'title_en' => 'Local', 'weight' => 60], $this->admin);
    $plans->addTarget($local, ['kpi_id' => $this->kCases->id, 'target_value' => 10, 'weight' => 70], $this->admin);

    expect(fn () => $plans->submit($child, $this->admin))->toThrow(ValidationException::class);
    $errors = implode(' ', $plans->validate($child));
    expect($errors)->toContain('60')->toContain('70')
        // The mandatory parent objective is not cascaded yet.
        ->toContain($w['dirObjective']->code);
    expect(fn () => app(PlanCascadeService::class)->decline($w['dirObjective']->fresh(), $child, 'not ours', $this->admin))->toThrow(ValidationException::class);
});

test('separation of duties: the submitter cannot approve their own plan', function (): void {
    $plans = app(PerformancePlanService::class);
    $org = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'ORGANIZATION', 'organization_id' => $this->orgA->id, 'title' => 'Plan'], $this->admin);
    $o = $plans->addObjective($org, ['code' => 'SO1', 'title_en' => 'A', 'weight' => 100], $this->admin);
    $plans->addTarget($o, ['kpi_id' => $this->kCases->id, 'target_value' => 10, 'weight' => 100], $this->admin);
    $plans->submit($org, $this->admin);

    expect(fn () => $plans->approve($org->fresh(), $this->admin))->toThrow(AuthorizationException::class);
});

// ── Employee (22–28) ────────────────────────────────────────────────────────

test('22 48 an employee sees only their own agreement', function (): void {
    epCascade();
    epActivateCycle();
    $mine = epAgreement($this->e1);
    $other = epAgreement($this->e2);

    $this->actingAs($this->u1)->get(route('employee.performance.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('agreement.id', $mine->id)->has('agreements', 1));
    $this->actingAs($this->u1)->get(route('employee.performance.index', ['agreement' => $other->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('agreement.id', $mine->id));
    $this->actingAs($this->u1)->get(route('performance.agreements.show', $other))->assertForbidden();
});

test('23 50 an employee cannot change official targets or another agreement (IDOR)', function (): void {
    epCascade();
    epActivateCycle();
    $mine = epAgreement($this->e1);
    $other = epAgreement($this->e2);
    $item = $mine->items()->first();

    $this->actingAs($this->u1)->put(route('performance.items.update', $item), ['expected_output' => 'x', 'weight' => 100, 'target_value' => 1])->assertForbidden();
    $this->actingAs($this->u1)->post(route('employee.performance.acknowledge', $other))->assertForbidden();
    $this->actingAs($this->u1)->post(route('performance.items.actuals.store', $other->items()->first()), ['period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'actual_value' => 999])->assertForbidden();
    expect((string) $item->fresh()->target_value)->toBe('1000.0000');
});

test('24 the employee can add evidence to their own agreement (private storage)', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    Storage::fake('local');

    $this->actingAs($this->u1)->post(route('employee.performance.evidence.store', $agreement), [
        'employee_performance_item_id' => $agreement->items()->first()->id, 'title' => 'Transfer register',
        'file' => UploadedFile::fake()->create('register.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $evidence = $agreement->evidence()->first();
    expect($evidence->evidence_type->value)->toBe('DOCUMENT')->and($evidence->toArray())->not->toHaveKey('file_path');
    Storage::disk('local')->assertExists($evidence->file_path);
    $this->actingAs($this->u2)->get(route('performance.evidence.download', $evidence))->assertForbidden();
});

test('25 26 daily activity feeds a KPI actual as evidence; the number of activities never scores', function (): void {
    $this->kDaily = epKpi('DA-CASES', 'HIGHER_IS_BETTER', 'SUM', 'DAILY_ACTIVITY');
    $w = epCascade();
    // Swap the position item's source to DAILY_ACTIVITY for this test.
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $item = $agreement->items()->first();
    $item->forceFill(['data_source_type' => 'DAILY_ACTIVITY', 'target_value' => 100])->save();

    // Ten activity entries, but only 3 + 4 + 0... cases of real output.
    foreach ([['2026-02-02', 3], ['2026-02-03', 4], ['2026-02-04', 0]] as [$date, $qty]) {
        $log = (new DailyActivityLog)->forceFill(['employee_id' => $this->e1->id, 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team->id, 'activity_date' => $date, 'status' => 'approved']);
        $log->save();
        (new DailyActivityItem)->forceFill(['daily_activity_log_id' => $log->id, 'title' => 'Transfer cases', 'progress_status' => 'completed', 'quantity' => $qty, 'employee_performance_item_id' => $item->id])->save();
        (new DailyActivityItem)->forceFill(['daily_activity_log_id' => $log->id, 'title' => 'Meeting', 'progress_status' => 'completed', 'quantity' => null])->save();
    }

    $actual = app(KpiActualService::class)->syncDailyActivity($item, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));
    expect((string) $actual->actual_value)->toBe('7.0000')->and($actual->source_type->value)->toBe('DAILY_ACTIVITY');

    // Re-sync replaces, never duplicates.
    app(KpiActualService::class)->syncDailyActivity($item, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));
    expect(KpiActual::query()->where('employee_performance_item_id', $item->id)->count())->toBe(1);

    $trace = app(EmployeeScoreCalculator::class)->trace($agreement);
    expect($trace['items'][0]['achievement'])->toBe('7.0000'); // 7 of 100 cases, not "6 activities"

    // A manual number cannot be added on top of a daily-activity KPI.
    expect(fn () => app(KpiActualService::class)->recordForItem($item, ['period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'actual_value' => 90], $this->manager))->toThrow(ValidationException::class);
});

test('27 28 the employee self-assesses but cannot calculate, adjust or finalize their own score', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);

    app(PerformanceReviewService::class)->employeeSubmit($agreement, ReviewType::YearEnd, ['employee_self_assessment' => 'Cleared the backlog.'], $this->u1);
    expect($agreement->reviews()->first()->status->value)->toBe('EMPLOYEE_SUBMITTED');

    $this->actingAs($this->u1)->post(route('performance.agreements.calculate', $agreement))->assertForbidden();
    expect(fn () => app(PerformanceResultService::class)->calculate($agreement, $this->u1))->toThrow(AuthorizationException::class);
});

// ── Review & results (29–35) ────────────────────────────────────────────────

test('29 30 a manager sees and manages only the covered team', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);

    $this->actingAs($this->manager)->get(route('performance.agreements.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('agreements.data', 1));
    $this->actingAs($this->outsider)->get(route('performance.agreements.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('agreements.data', 0));
    $this->actingAs($this->outsider)->get(route('performance.agreements.show', $agreement))->assertForbidden();
});

/** Complete a year-end with actuals, competency ratings and a calculated result. */
function epYearEnd(EmployeePerformanceAgreement $agreement, string $actual = '900'): PerformanceResult
{
    $t = test();
    $item = $agreement->items()->first();
    app(KpiActualService::class)->recordForItem($item, ['period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'actual_value' => $actual], $t->manager);
    $user = User::query()->where('email', $agreement->employee->email)->firstOrFail();
    app(PerformanceReviewService::class)->employeeSubmit($agreement, ReviewType::YearEnd, ['employee_self_assessment' => 'Done.'], $user);
    $ratings = $agreement->competencyAssessments()->pluck('competency_id')->mapWithKeys(fn ($id) => [$id => 4])->all();
    app(PerformanceReviewService::class)->managerComplete($agreement, ReviewType::YearEnd, ['manager_comment' => 'Solid year.', 'manager_private_note' => 'Consider for promotion panel.', 'ratings' => $ratings], $t->manager);

    return app(PerformanceResultService::class)->calculate($agreement->fresh(), $t->manager);
}

test('31 32 35 year-end workflow, and the stored result equals its own calculation trace', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $result = epYearEnd($agreement, '900');

    // 900 / 1000 = 90% × weight 100 = 90 results; competencies 4/5 = 80; 90×0.8 + 80×0.2 = 88.
    $trace = $result->snapshot_json;
    expect((string) $result->results_score)->toBe('90.0000')
        ->and((string) $result->competency_score)->toBe('80.0000')
        ->and((string) $result->final_score)->toBe('88.0000')
        ->and($trace['final_score'])->toBe('88.0000')
        ->and($trace['results_contribution'])->toBe('72.0000')
        ->and($trace['competency_contribution'])->toBe('16.0000')
        ->and($trace['items'][0]['weighted'])->toBe('90.0000')
        ->and($result->rating_label_en)->toBe('Very Good');
});

test('33 a score adjustment needs the setting, a reason, and a different approver', function (): void {
    epCascade();
    epActivateCycle();
    $result = epYearEnd(epAgreement($this->e1));
    $results = app(PerformanceResultService::class);

    expect(fn () => $results->requestAdjustment($result, '92', 'Exceptional crisis work', $this->manager))->toThrow(ValidationException::class);
    epSetting('allow_score_adjustment', true);
    $adjustment = $results->requestAdjustment($result, '92', 'Exceptional crisis work', $this->manager);

    expect(fn () => $results->decideAdjustment($adjustment, true, null, $this->u1))->toThrow(AuthorizationException::class);
    $results->decideAdjustment($adjustment, true, 'Evidence reviewed', $this->admin);

    expect((string) $result->fresh()->final_score)->toBe('92.0000')
        ->and((string) $result->fresh()->calculated_score)->toBe('88.0000')
        ->and(AuditLog::query()->where('event_type', 'performance_score_adjusted')->count())->toBe(2);
});

test('34 a finalized result is immutable', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $result = epYearEnd($agreement);
    app(PerformanceResultService::class)->finalize($result, $this->admin);

    expect($result->fresh()->status)->toBe(ResultStatus::PendingRelease)
        ->and(fn () => $result->fresh()->forceFill(['final_score' => '99'])->save())->toThrow(LogicException::class)
        ->and(fn () => app(PerformanceResultService::class)->calculate($agreement->fresh(), $this->manager))->toThrow(ValidationException::class);

    // The employee sees it only once released.
    $this->actingAs($this->u1)->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('agreement.result', null)->where('agreement.result_hidden', true));
    app(PerformanceResultService::class)->release($result->fresh(), $this->admin);
    $this->actingAs($this->u1)->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('agreement.result.final_score', '88.0000'));
});

test('51 52 confidential notes and improvement plans never reach the employee', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    epYearEnd($agreement);
    app(PerformanceReviewService::class)->addCheckin($agreement->fresh(), ['checkin_date' => '2026-06-15', 'manager_comment' => 'On track', 'manager_private_note' => 'Watch attendance'], $this->manager);
    app(DevelopmentPlanService::class)->createImprovementPlan($agreement->fresh(), ['identified_gap' => 'Accuracy', 'required_improvement' => 'Fewer returns', 'start_date' => '2026-07-01', 'end_date' => '2026-09-30'], $this->manager);

    $response = $this->actingAs($this->u1)->get(route('employee.performance.index'));
    $json = json_encode($response->viewData('page')['props']);
    expect($json)->not->toContain('Watch attendance')->not->toContain('Consider for promotion panel')->not->toContain('Fewer returns')->toContain('On track');
});

// ── Unit / organization aggregation (36–41) ─────────────────────────────────

test('36 37 40 unit KPIs roll up employee outputs once; employee scores are never summed', function (): void {
    $w = epCascade();
    epActivateCycle();
    $a1 = epAgreement($this->e1);
    $a2 = epAgreement($this->e2);
    $actuals = app(KpiActualService::class);
    $actuals->recordForItem($a1->items()->first(), ['period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'actual_value' => 300], $this->manager);
    $actuals->recordForItem($a2->items()->first(), ['period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'actual_value' => 200], $this->manager);

    $orgTrace = app(PerformanceAggregationService::class)->planScore($w['org']->fresh(), Carbon::parse('2026-12-31'));
    $target = $orgTrace['objectives'][0]['targets'][0];

    // 300 + 200 = 500 cases reach the organization ONCE (not 4× through each level).
    expect($target['actual'])->toBe('500.0000')
        ->and($target['achievement'])->toBe('50.0000')
        ->and($orgTrace['score'])->toBe('50.0000');

    // Each employee row is consumed exactly once per level.
    $positionTarget = KpiTarget::query()->where('performance_plan_id', $w['position']->id)->first();
    expect(KpiContribution::query()->where('parent_target_id', $positionTarget->id)->count())->toBe(2)
        ->and(KpiContribution::query()->whereIn('source_actual_id', KpiActual::query()->whereNotNull('employee_performance_item_id')->select('id'))->count())->toBe(2);

    // Re-running changes nothing: no double counting on recalculation.
    $again = app(PerformanceAggregationService::class)->planScore($w['org']->fresh(), Carbon::parse('2026-12-31'));
    expect($again['objectives'][0]['targets'][0]['actual'])->toBe('500.0000');
});

test('38 39 weighted-average and ratio KPIs aggregate from totals, not from averaged percentages', function (): void {
    $plans = app(PerformancePlanService::class);
    $org = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'ORGANIZATION', 'organization_id' => $this->orgA->id, 'title' => 'Plan'], $this->admin);
    $o = $plans->addObjective($org, ['code' => 'Q', 'title_en' => 'Quality', 'weight' => 100], $this->admin);
    $errTarget = $plans->addTarget($o, ['kpi_id' => $this->kErrors->id, 'target_numerator' => 5, 'target_denominator' => 100, 'weight' => 50], $this->admin);
    $timeTarget = $plans->addTarget($o, ['kpi_id' => $this->kTime->id, 'target_value' => 3, 'weight' => 50], $this->admin);
    epPublish($org);

    $unit = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->directorate->id, 'parent_plan_id' => $org->id, 'title' => 'D'], $this->admin);
    app(PlanCascadeService::class)->cascade($o->fresh(), $unit, CascadeMode::Accept, ['weight' => 100], $this->admin);
    epPublish($unit);
    $unit2 = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'parent_plan_id' => $org->id, 'title' => 'R'], $this->admin);
    app(PlanCascadeService::class)->cascade($o->fresh(), $unit2, CascadeMode::Accept, ['weight' => 100], $this->admin);
    epPublish($unit2);

    $actuals = app(KpiActualService::class);
    $u1Err = KpiTarget::query()->where('performance_plan_id', $unit->id)->where('kpi_id', $this->kErrors->id)->first();
    $u2Err = KpiTarget::query()->where('performance_plan_id', $unit2->id)->where('kpi_id', $this->kErrors->id)->first();
    $actuals->recordForTarget($u1Err, ['period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'actual_numerator' => 1, 'actual_denominator' => 10], $this->admin);
    $actuals->recordForTarget($u2Err, ['period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'actual_numerator' => 9, 'actual_denominator' => 190], $this->admin);

    $trace = app(PerformanceAggregationService::class)->planScore($org->fresh(), Carbon::parse('2026-12-31'));
    $err = collect($trace['objectives'][0]['targets'])->firstWhere('kpi_code', 'ERR');
    // (1 + 9) ÷ (10 + 190) = 5%, not (10% + 4.7%) ÷ 2.
    expect($err['actual'])->toBe('5.0000')->and($err['achievement'])->toBe('100.0000');
});

test('41 organization performance is the weighted achievement of its own objectives', function (): void {
    $plans = app(PerformancePlanService::class);
    $org = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'ORGANIZATION', 'organization_id' => $this->orgA->id, 'title' => 'Plan'], $this->admin);
    $a = $plans->addObjective($org, ['code' => 'A', 'title_en' => 'A', 'weight' => 30], $this->admin);
    $b = $plans->addObjective($org, ['code' => 'B', 'title_en' => 'B', 'weight' => 70], $this->admin);
    $ta = $plans->addTarget($a, ['kpi_id' => $this->kCases->id, 'target_value' => 100, 'weight' => 100], $this->admin);
    $tb = $plans->addTarget($b, ['kpi_id' => $this->kTime->id, 'target_value' => 2, 'weight' => 100], $this->admin);
    epPublish($org);
    app(KpiActualService::class)->recordForTarget($ta->fresh(), ['period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'actual_value' => 80], $this->admin);
    app(KpiActualService::class)->recordForTarget($tb->fresh(), ['period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'actual_value' => 4], $this->admin);

    // A: 80% × 30 = 24; B: 2/4 = 50% × 70 = 35 → 59.
    expect(app(PerformanceAggregationService::class)->planScore($org->fresh(), Carbon::parse('2026-12-31'))['score'])->toBe('59.0000');
});

// ── Calibration & appeals (42–47) ───────────────────────────────────────────

test('42 43 44 calibration needs authority, keeps the original score and is audited', function (): void {
    epCascade();
    epActivateCycle();
    $result = epYearEnd(epAgreement($this->e1));
    $calibration = app(PerformanceCalibrationService::class);

    expect(fn () => $calibration->createSession($this->cycle, ['organization_id' => $this->orgA->id, 'title' => 'x'], $this->manager))->toThrow(AuthorizationException::class);
    $session = $calibration->createSession($this->cycle, ['organization_id' => $this->orgA->id, 'title' => 'HR calibration'], $this->admin);
    $calibration->addResults($session, [$result->id], $this->admin);
    $item = $session->items()->first();
    $calibration->decide($item, '85', 'Aligned with peers across teams', $this->admin);
    $calibration->finalize($session->fresh(), $this->approver);

    $result->refresh();
    expect((string) $result->calculated_score)->toBe('88.0000')
        ->and((string) $result->calibrated_score)->toBe('85.0000')
        ->and((string) $result->final_score)->toBe('85.0000')
        ->and($result->status)->toBe(ResultStatus::PendingRelease)
        ->and((string) $item->fresh()->manager_score)->toBe('88.0000')
        ->and($result->adjustments()->where('adjustment_type', 'CALIBRATION')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'performance_calibrated')->exists())->toBeTrue();
});

test('45 46 47 appeal within the window; only the committee decides; history is retained', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $result = epYearEnd($agreement);
    app(PerformanceResultService::class)->finalize($result, $this->admin);
    app(PerformanceResultService::class)->release($result->fresh(), $this->admin);

    $memberEmployee = epEmployee('EP-C', 'chair@ep.test', $this->orgA, $this->directorate, $this->position);
    $chair = epUser(PerformanceRoles::APPEAL_COMMITTEE_ROLE, PerformanceRoles::APPEAL_COMMITTEE_PERMISSIONS, 'chair@ep.test');
    $committee = GrievanceCommittee::query()->create(['organization_id' => $this->orgA->id, 'committee_type' => CommitteeType::PerformanceAppeal->value, 'name_en' => 'Appeal committee', 'status' => 'active']);
    $committee->members()->create(['employee_id' => $memberEmployee->id, 'role' => 'chairperson', 'status' => 'active', 'effective_from' => '2026-01-01']);

    $appeals = app(PerformanceAppealService::class);
    $appeal = $appeals->file($result->fresh(), 'The error-rate figure excludes cases returned by the receiving bureau.', null, $this->u1);
    expect($appeal->committee_id)->toBe($committee->id);

    // A manager with the permission but not on the committee cannot decide.
    $outsiderDecider = epUser('Decider', ['performance_appeals.decide']);
    expect(fn () => $appeals->decide($appeal, AppealDecision::Upheld, 'x', '95', $outsiderDecider))->toThrow(AuthorizationException::class);

    $appeals->decide($appeal, AppealDecision::PartiallyUpheld, 'Returned cases excluded.', '91', $chair);

    $current = PerformanceResult::query()->where('agreement_id', $agreement->id)->where('is_current', true)->first();
    expect($current->revision_no)->toBe(2)->and((string) $current->final_score)->toBe('91.0000')
        ->and((string) $result->fresh()->final_score)->toBe('88.0000')
        ->and($result->fresh()->is_current)->toBeFalse()
        ->and($appeal->fresh()->decision)->toBe(AppealDecision::PartiallyUpheld);

    // Window closed → no new appeal.
    Carbon::setTestNow(now()->addDays(60));
    expect(fn () => $appeals->file($current, 'Late appeal text that is long enough.', null, $this->u1))->toThrow(ValidationException::class);
});

// ── Transfer (97) ───────────────────────────────────────────────────────────

test('97 a mid-cycle transfer closes the old agreement, keeps its actuals in the old unit, and never double counts', function (): void {
    $w = epCascade();
    epActivateCycle();
    $old = epAgreement($this->e1);
    app(KpiActualService::class)->recordForItem($old->items()->first(), ['period_start' => '2026-01-01', 'period_end' => '2026-05-31', 'actual_value' => 120], $this->manager);

    $newAssignment = EmployeeAssignment::query()->create(['employee_id' => $this->e1->id, 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'position_id' => $this->position2->id, 'assignment_status' => 'active', 'effective_from' => '2026-07-01', 'is_current' => true]);
    $next = app(EmployeeAgreementService::class)->transfer($old->fresh(), $newAssignment, $this->admin);

    expect($old->fresh()->status)->toBe(AgreementStatus::Closed)
        ->and($old->fresh()->effective_to->toDateString())->toBe('2026-06-30')
        ->and($next->organization_unit_id)->toBe($this->team2->id)
        ->and($next->effective_from->toDateString())->toBe('2026-07-01')
        ->and(KpiActual::query()->where('agreement_id', $old->id)->count())->toBe(1);

    // The closed agreement's output still reaches the organization exactly once.
    $trace = app(PerformanceAggregationService::class)->planScore($w['org']->fresh(), Carbon::parse('2026-12-31'));
    expect($trace['objectives'][0]['targets'][0]['actual'])->toBe('120.0000');
});

// ── Security & scale (49, 53, 54, 59) ───────────────────────────────────────

test('49 an organization B admin cannot read or change organization A', function (): void {
    $w = epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);

    $this->actingAs($this->adminB)->get(route('performance.agreements.show', $agreement))->assertForbidden();
    $this->actingAs($this->adminB)->get(route('performance.agreements.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('agreements.data', 0));
    $this->actingAs($this->adminB)->post(route('performance.plans.workflow', ['plan' => $w['org']->id, 'action' => 'submit']))->assertForbidden();
    $this->actingAs($this->adminB)->get(route('performance.lookups.units', ['organization_id' => $this->orgA->id]))->assertForbidden();
});

test('53 no API route exposes performance data', function (): void {
    $api = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/') && preg_match('/perform|kpi|apprais/i', $r->uri()));
    expect($api->all())->toBe([]);
});

test('54 mass assignment cannot set workflow or score fields', function (): void {
    $w = epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);

    // Extra fields on a normal update are ignored.
    $plan = app(PerformancePlanService::class)->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'parent_plan_id' => $w['dir']->id, 'title' => 'x', 'status' => 'PUBLISHED', 'live_key' => 'forged'], $this->admin);
    expect($plan->fresh()->status)->toBe(PlanStatus::Draft)->and($plan->fresh()->live_key)->toBeNull();

    $this->actingAs($this->manager)->put(route('performance.items.update', $agreement->items()->first()), ['expected_output' => 'x', 'weight' => 100, 'is_current' => false, 'agreement_id' => 'forged'])
        ->assertSessionHasErrors(); // agreement is ACTIVE: items are locked; and the forged keys are never fillable
    expect($agreement->items()->first()->is_current)->toBeTrue();
});

test('59 employee selectors search server-side, need 2+ characters and return at most 20 rows in scope', function (): void {
    foreach (range(1, 30) as $n) {
        epEmployee("EP-BULK-{$n}", "bulk{$n}@ep.test", $this->orgA, $this->team, $this->position);
    }

    $this->actingAs($this->admin)->getJson(route('performance.lookups.employees', ['q' => 'E']))->assertExactJson([]);
    $rows = $this->actingAs($this->admin)->getJson(route('performance.lookups.employees', ['q' => 'EP-BULK']))->json();
    expect($rows)->toHaveCount(20);
    expect($this->actingAs($this->adminB)->getJson(route('performance.lookups.employees', ['q' => 'EP-BULK']))->json())->toBe([]);

    // Employees without an EPMS management permission cannot use the pickers at all.
    $this->actingAs($this->u1)->getJson(route('performance.lookups.employees', ['q' => 'EP-BULK']))->assertForbidden();
    $this->actingAs($this->u1)->getJson(route('performance.lookups.organizations'))->assertForbidden();
});

// ── Pages (UI contract) ─────────────────────────────────────────────────────

test('every EPMS page renders its component with the props it needs, scoped to the viewer', function (): void {
    $w = epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $result = epYearEnd($agreement);
    app(PerformanceAggregationService::class)->planScore($w['org']->fresh(), Carbon::parse('2026-06-15'));
    $session = app(PerformanceCalibrationService::class)->createSession($this->cycle, ['organization_id' => $this->orgA->id, 'title' => 'HR calibration'], $this->admin);

    $this->actingAs($this->admin);
    $this->get(route('performance.dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Dashboard')
        ->has('organizationPlans', 1)->where('organizationPlans.0.id', $w['org']->id)->where('agreementTotal', 1)->has('atRisk')->has('distribution'));
    $this->get(route('performance.cycles.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Cycles/Index')
        ->has('cycles.data', 1)->where('cycles.data.0.next', ['MID_YEAR_REVIEW', 'YEAR_END_REVIEW'])->where('canCreateGlobal', false)->has('organizations', 1));
    $this->get(route('performance.kpis.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Kpis/Index')
        ->has('kpis.data', 3)->has('options.system_sources')->where('can.global', false));
    $this->get(route('performance.plans.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Plans/Index')
        ->has('plans.data', 4)->has('publishedParents', 3)->has('organizations', 1));
    $this->get(route('performance.plans.show', $w['dir']))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Plans/Show')
        ->where('plan.id', $w['dir']->id)->has('objectives', 1)->has('parentObjectives', 1)->where('parentObjectives.0.cascaded', true)
        ->has('childPlans', 1)->has('versions', 1)->where('can.newVersion', true));
    $this->get(route('performance.agreements.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Agreements/Index')->has('agreements.data', 1));
    $this->get(route('performance.agreements.show', $agreement))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Agreements/Show')
        ->where('agreement.id', $agreement->id)->has('agreement.items', 1)->where('agreement.result.id', $result->id)->has('agreement.result.trace.items', 1)->where('can.finalize', true));
    $this->get(route('performance.calibration.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Calibration/Index')->has('sessions.data', 1));
    $this->get(route('performance.calibration.show', $session))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Calibration/Show')
        ->has('candidates', 1)->where('candidates.0.id', $result->id));
    $this->get(route('performance.appeals.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Appeals/Index'));
    foreach (['results', 'distribution', 'agreement_completion', 'missing_actuals', 'appeals', 'calibration', 'improvement_plans'] as $report) {
        $this->get(route('performance.reports.index', ['report' => $report]))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Reports/Index')->where('report', $report)->has('columns'));
    }
    $this->get(route('performance.settings.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Performance/Settings')->has('fields', 19)->has('scales'));

    $this->actingAs($this->u1)->get(route('employee.performance.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Employee/MyPerformance')
        ->where('agreement.id', $agreement->id)->has('agreements', 1)->where('appealWindowDays', 15));

    // Organization B sees none of organization A's rows on the same pages.
    $this->actingAs($this->adminB)->get(route('performance.plans.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('plans.data', 0)->has('publishedParents', 0));
    $this->actingAs($this->adminB)->get(route('performance.agreements.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('agreements.data', 0));
    $this->actingAs($this->adminB)->get(route('performance.reports.index', ['report' => 'results']))->assertInertia(fn (AssertableInertia $p) => $p->has('rows.data', 0)->has('cycles', 0));
});

test('the CSV export is scoped, neutralizes formulas and is audited', function (): void {
    epCascade();
    epActivateCycle();
    $this->e1->update(['full_name' => '=HYPERLINK("http://x")']);
    epYearEnd(epAgreement($this->e1));

    $csv = $this->actingAs($this->admin)->get(route('performance.reports.export', ['report' => 'results']))->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK')
        ->and(AuditLog::query()->where('event_type', 'export_performed')->exists())->toBeTrue();
    expect($this->actingAs($this->adminB)->get(route('performance.reports.export', ['report' => 'results']))->streamedContent())->not->toContain('HYPERLINK');
});

// ── Localization (55–58) ────────────────────────────────────────────────────

test('55 every server message and notification has an Amharic translation', function (): void {
    $flatten = function (array $tree, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($tree as $key => $value) {
            $keys = [...$keys, ...(is_array($value) ? $flatten($value, "{$prefix}{$key}.") : ["{$prefix}{$key}"])];
        }

        return $keys;
    };
    $en = $flatten(require lang_path('en/performance.php'));
    $am = $flatten(require lang_path('am/performance.php'));

    expect(array_values(array_diff($en, $am)))->toBe([])->and(array_values(array_diff($am, $en)))->toBe([]);
});

test('56 validation errors come back in Amharic when the user works in Amharic', function (): void {
    app()->setLocale('am');
    $plan = app(PerformancePlanService::class)->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'ORGANIZATION', 'organization_id' => $this->orgA->id, 'title' => 'Plan'], $this->admin);

    $messages = '';
    try {
        app(PerformancePlanService::class)->submit($plan, $this->admin);
    } catch (ValidationException $e) {
        $messages = collect($e->errors())->flatten()->implode(' ');
    }

    expect($messages)->toContain(__('performance.validation.no_objectives'))
        ->and(__('performance.validation.no_objectives'))->not->toBe(trans('performance.validation.no_objectives', [], 'en'));
});

test('57 dates entered in either locale are stored as Gregorian calendar dates', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $item = $agreement->items()->first();

    app()->setLocale('am');
    $this->actingAs($this->manager)->post(route('performance.items.actuals.store', $item), ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'actual_value' => 12])->assertSessionHasNoErrors();

    $row = KpiActual::query()->where('employee_performance_item_id', $item->id)->firstOrFail();
    expect($row->getRawOriginal('period_start'))->toBe('2026-03-01')->and($row->getRawOriginal('period_end'))->toBe('2026-03-31');
});

test('58 seeded rating bands and competencies carry Amharic labels', function (): void {
    expect(PerformanceRatingBand::query()->whereNull('label_am')->count())->toBe(0)
        ->and(PerformanceRatingBand::query()->count())->toBeGreaterThan(0)
        ->and(Competency::query()->whereNull('name_am')->count())->toBe(0);
});

// ── Rating scale (60–62) ────────────────────────────────────────────────────

test('60 rating bands are inclusive at the lower edge and never overlap', function (): void {
    $calculator = app(EmployeeScoreCalculator::class);

    expect($calculator->ratingFor('90')['label_en'])->toBe('Exceptional')
        ->and($calculator->ratingFor('89.9999')['label_en'])->toBe('Very Good')
        ->and($calculator->ratingFor('80')['label_en'])->toBe('Very Good')
        ->and($calculator->ratingFor('60')['label_en'])->toBe('Needs Improvement')
        ->and($calculator->ratingFor('59.9999')['label_en'])->toBe('Unsatisfactory')
        ->and($calculator->ratingFor('0')['label_en'])->toBe('Unsatisfactory')
        ->and($calculator->ratingFor('120')['label_en'])->toBe('Exceptional');
});

test('61 a band change applies to new calculations only; a finalized result keeps its rating and the change is audited', function (): void {
    epCascade();
    epActivateCycle();
    $result = epYearEnd(epAgreement($this->e1));
    app(PerformanceResultService::class)->finalize($result, $this->admin);

    $band = PerformanceRatingBand::query()->where('label_en', 'Very Good')->firstOrFail();
    $this->actingAs($this->admin)->put(route('performance.settings.bands.update', $band->id), ['min_score' => 80, 'max_score' => 89.9999, 'label_en' => 'Strong', 'label_am' => 'ጠንካራ'])->assertSessionHasNoErrors();

    expect($result->fresh()->rating_label_en)->toBe('Very Good')
        ->and($result->fresh()->snapshot_json['rating']['label_en'])->toBe('Very Good')
        ->and(app(EmployeeScoreCalculator::class)->ratingFor('88')['label_en'])->toBe('Strong')
        ->and(AuditLog::query()->where('event_type', 'setting_updated')->exists())->toBeTrue();
});

test('62 component weights must total 100 and only settings managers may change the policy', function (): void {
    $fields = collect(app(SystemSettingsService::class)->getGroupForAdmin(SystemSettingsRegistry::GROUP_PERFORMANCE))->mapWithKeys(fn ($f) => [$f['key'] => $f['value']])->all();

    $this->actingAs($this->admin)->patch(route('performance.settings.update'), [...$fields, 'results_weight' => 70, 'competency_weight' => 20])
        ->assertSessionHasErrors(['competency_weight' => __('performance.validation.component_weights', ['total' => 90])]);
    $this->actingAs($this->manager)->get(route('performance.settings.index'))->assertForbidden();
    $this->actingAs($this->admin)->patch(route('performance.settings.update'), [...$fields, 'results_weight' => 70, 'competency_weight' => 30])->assertSessionHasNoErrors();

    expect(app(EpmsSettings::class)->componentWeights())->toBe(['results' => 70, 'competency' => 30]);
});

test('editing a target keeps the cascade rules: no re-pointing to a foreign parent target, period inside the cycle', function (): void {
    $w = epCascade();
    $plans = app(PerformancePlanService::class);
    $draft = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'parent_plan_id' => $w['dir']->id, 'title' => 'Records'], $this->admin);
    [$objective] = app(PlanCascadeService::class)->cascade($w['dirObjective']->fresh(), $draft, CascadeMode::Accept, ['weight' => 100, 'copy_targets' => true], $this->admin);
    $target = $objective->targets()->firstOrFail();
    $foreign = KpiTarget::query()->where('performance_plan_id', $w['org']->id)->firstOrFail();

    $this->actingAs($this->admin)->put(route('performance.targets.update', $target), ['weight' => 100, 'parent_target_id' => $foreign->id])->assertSessionHasErrors('parent_target_id');
    $this->actingAs($this->admin)->put(route('performance.targets.update', $target), ['weight' => 100, 'period_start' => '2025-12-01'])->assertSessionHasErrors('period_start');
    $this->actingAs($this->admin)->put(route('performance.targets.update', $target), ['weight' => 100, 'target_value' => 250])->assertSessionHasNoErrors();

    expect($target->fresh()->parent_target_id)->not->toBe($foreign->id)
        ->and((string) $target->fresh()->target_value)->toBe('250.0000')
        ->and($target->fresh()->getRawOriginal('period_start'))->toBe('2026-01-01');
});

test('editing an agreement item never unlinks it from its objective or plan target', function (): void {
    epCascade();
    foreach ([CycleStatus::Planning, CycleStatus::Cascaded, CycleStatus::Agreement] as $status) {
        app(PerformanceCycleService::class)->transition($this->cycle->fresh(), $status, $this->admin);
    }
    $agreement = app(EmployeeAgreementService::class)->create($this->e1, EmployeeAssignment::query()->where('employee_id', $this->e1->id)->firstOrFail(), $this->cycle->fresh(), $this->manager);
    $item = $agreement->items()->firstOrFail();

    $this->actingAs($this->manager)->put(route('performance.items.update', $item), ['expected_output' => 'Process 500 transfers', 'weight' => 100, 'objective_id' => null, 'position_target_id' => null])->assertSessionHasNoErrors();

    expect($item->fresh()->objective_id)->toBe($item->objective_id)->not->toBeNull()
        ->and($item->fresh()->position_target_id)->toBe($item->position_target_id)->not->toBeNull()
        ->and($item->fresh()->expected_output)->toBe('Process 500 transfers');
});

test('employee portal review actions respect windows, submission state and permissions', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $this->cycle->forceFill(['midyear_review_start_date' => '2026-06-01', 'midyear_review_end_date' => '2026-06-30',
        'yearend_review_start_date' => '2026-12-01', 'yearend_review_end_date' => '2026-12-31'])->save();

    $this->actingAs($this->u1)->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p
        ->where('can.selfAssess.MID_YEAR', true)->where('can.selfAssess.YEAR_END', false)->where('can.appeal', false));
    $this->post(route('employee.performance.reviews.submit', [$agreement, 'year_end']), ['employee_self_assessment' => 'My work summary'])
        ->assertSessionHasErrors('review');
    $this->post(route('employee.performance.reviews.submit', [$agreement, 'mid_year']), ['employee_self_assessment' => 'My work summary'])
        ->assertSessionHasNoErrors();
    $this->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('can.selfAssess.MID_YEAR', false));
    app(PerformanceReviewService::class)->managerReturn($agreement, ReviewType::MidYear, 'Please add supporting detail.', $this->manager);
    $this->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('can.selfAssess.MID_YEAR', true));
    Role::findByName(DailyActivityRoles::EMPLOYEE_ROLE)->revokePermissionTo('performance_reviews.self_assess');
    $this->u1->forgetCachedPermissions();
    $this->actingAs($this->u1->fresh())->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('can.selfAssess.MID_YEAR', false));
});

test('employee check-in updates preserve other fields and refuse closed agreements', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $checkin = app(PerformanceReviewService::class)->addCheckin($agreement, [
        'checkin_date' => '2026-06-15', 'employee_summary' => 'Initial progress', 'blockers' => 'Waiting for data',
        'support_required' => 'Data access', 'learning_needs' => 'Report training', 'manager_comment' => 'Keep following up',
    ], $this->manager);
    $this->actingAs($this->u1)->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p
        ->where('agreement.checkins.0.blockers', 'Waiting for data')->where('agreement.checkins.0.support_required', 'Data access')
        ->where('agreement.checkins.0.learning_needs', 'Report training'));
    $this->post(route('employee.performance.checkins.note', $checkin), ['employee_summary' => 'Updated progress'])->assertSessionHasNoErrors();
    expect($checkin->fresh()->blockers)->toBe('Waiting for data')
        ->and($checkin->fresh()->learning_needs)->toBe('Report training')->and($checkin->fresh()->manager_comment)->toBe('Keep following up');
    $agreement->forceFill(['status' => AgreementStatus::Closed])->save();
    $this->post(route('employee.performance.checkins.note', $checkin), ['employee_summary' => 'Must not change'])->assertSessionHasErrors('agreement');
    expect($checkin->fresh()->employee_summary)->toBe('Updated progress');
});

test('employee portal appeal action closes after the deadline or an open appeal', function (): void {
    epCascade();
    epActivateCycle();
    $agreement = epAgreement($this->e1);
    $result = epYearEnd($agreement);
    app(PerformanceResultService::class)->finalize($result, $this->admin);
    app(PerformanceResultService::class)->release($result->fresh(), $this->admin);
    $this->actingAs($this->u1)->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('can.appeal', true));
    $result->forceFill(['released_at' => now()->subDays(16)])->save();
    $this->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('can.appeal', false));
    $result->forceFill(['released_at' => now()])->save();
    app(PerformanceAppealService::class)->file($result->fresh(), 'Please recheck the supporting records for this result.', null, $this->u1);
    $this->get(route('employee.performance.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('can.appeal', false));
});

test('the My Portal dashboard shows the employee their agreement, KPI progress and next steps, but never an unreleased score', function (): void {
    epCascade();

    // No agreement yet: the panel is there with an empty state.
    $this->actingAs($this->u1)->get(route('employee.portal'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Employee/Portal')
        ->where('performance.agreement', null)->where('performance.actions', []));

    // Sent to the employee: acknowledging is the next step.
    foreach ([CycleStatus::Planning, CycleStatus::Cascaded, CycleStatus::Agreement] as $status) {
        app(PerformanceCycleService::class)->transition($this->cycle->fresh(), $status, $this->admin);
    }
    $service = app(EmployeeAgreementService::class);
    $agreement = $service->create($this->e1, EmployeeAssignment::query()->where('employee_id', $this->e1->id)->firstOrFail(), $this->cycle->fresh(), $this->manager);
    $service->submitToEmployee($agreement, $this->manager);
    $this->actingAs($this->u1)->get(route('employee.portal'))->assertInertia(fn (AssertableInertia $p) => $p
        ->where('performance.agreement.status', 'PENDING_EMPLOYEE_REVIEW')->where('performance.actions.0.key', 'acknowledge')
        ->has('performance.kpis', 1)->where('performance.kpis.0.health', 'NOT_REPORTED')->where('performance.counts.total', 1));

    // Active with a measured actual: progress shows; the year-end prompt follows the cycle phase.
    $service->acknowledge($agreement->fresh(), $this->u1);
    $service->approve($agreement->fresh(), $this->manager);
    app(PerformanceCycleService::class)->transition($this->cycle->fresh(), CycleStatus::Active, $this->admin);
    app(KpiActualService::class)->recordForItem($agreement->items()->first(), ['period_start' => '2026-01-01', 'period_end' => '2026-06-15', 'actual_value' => 500], $this->manager);
    app(PerformanceCycleService::class)->transition($this->cycle->fresh(), CycleStatus::YearEndReview, $this->admin);
    $this->actingAs($this->u1)->get(route('employee.portal'))->assertInertia(fn (AssertableInertia $p) => $p
        ->where('performance.agreement.status', 'ACTIVE')->where('performance.kpis.0.achievement', '50.0000')
        // 500 of 1000 by mid-June is on pace for the year (SUM KPIs are judged against elapsed time).
        ->where('performance.kpis.0.health', 'ON_TRACK')
        ->where('performance.actions', [['key' => 'self_assessment', 'review' => 'YEAR_END']])
        ->where('performance.result', null));

    // A calculated but unreleased result never reaches the portal; a released one does, with the appeal prompt.
    $result = epYearEnd($agreement->fresh(), '900');
    app(PerformanceResultService::class)->finalize($result, $this->admin);
    $this->actingAs($this->u1)->get(route('employee.portal'))->assertInertia(fn (AssertableInertia $p) => $p->where('performance.result', null));
    app(PerformanceResultService::class)->release($result->fresh(), $this->admin);
    $this->actingAs($this->u1)->get(route('employee.portal'))->assertInertia(fn (AssertableInertia $p) => $p
        ->where('performance.result.final_score', (string) $result->fresh()->final_score)->where('performance.actions', [['key' => 'result_released']]));

    // Accounts without EPMS access get no panel at all.
    $this->actingAs($this->u2)->get(route('employee.portal'))->assertInertia(fn (AssertableInertia $p) => $p->where('performance.agreement', null));
    epSetting('enabled', false);
    $this->actingAs($this->u1)->get(route('employee.portal'))->assertInertia(fn (AssertableInertia $p) => $p->where('performance', null));
});

test('forms that submit empty optional fields never hit a NOT NULL column (priority, period type, data source)', function (): void {
    $w = epCascade();
    $plans = app(PerformancePlanService::class);
    $draft = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'UNIT', 'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->team2->id, 'parent_plan_id' => $w['dir']->id, 'title' => 'Records'], $this->admin);

    // The objective form sends every field, with empty ones as null (as the UI does).
    $this->actingAs($this->admin)->post(route('performance.plans.objectives.store', $draft), [
        'code' => '001', 'title_en' => 'Training', 'title_am' => 'ስልጠና', 'description_en' => null, 'objective_type' => 'STRATEGIC', 'is_mandatory' => true, 'weight' => 50, 'priority' => null,
    ])->assertSessionHasNoErrors();
    $objective = $draft->objectives()->where('code', '001')->firstOrFail();
    expect($objective->priority)->toBe(0);

    $this->actingAs($this->admin)->put(route('performance.objectives.update', $objective), ['title_en' => 'Training', 'weight' => 60, 'priority' => null])->assertSessionHasNoErrors();
    expect($objective->fresh()->priority)->toBe(0)->and((string) $objective->fresh()->weight)->toBe('60.0000');

    $this->actingAs($this->admin)->post(route('performance.objectives.targets.store', $objective), ['kpi_id' => $this->kCases->id, 'weight' => 100, 'target_value' => 10, 'period_type' => null, 'period_start' => null, 'period_end' => null, 'parent_target_id' => null])->assertSessionHasNoErrors();
    $target = $objective->targets()->firstOrFail();
    $this->actingAs($this->admin)->put(route('performance.targets.update', $target), ['weight' => 100, 'target_value' => 12, 'period_type' => null])->assertSessionHasNoErrors();
    expect($target->fresh()->period_type->value)->toBe('ANNUAL');

    // Agreement item: a null data source keeps the item's source.
    foreach ([CycleStatus::Planning, CycleStatus::Cascaded, CycleStatus::Agreement] as $status) {
        app(PerformanceCycleService::class)->transition($this->cycle->fresh(), $status, $this->admin);
    }
    $agreement = app(EmployeeAgreementService::class)->create($this->e1, EmployeeAssignment::query()->where('employee_id', $this->e1->id)->firstOrFail(), $this->cycle->fresh(), $this->manager);
    $item = $agreement->items()->firstOrFail();
    $this->actingAs($this->manager)->put(route('performance.items.update', $item), ['expected_output' => 'x', 'weight' => 100, 'data_source_type' => null])->assertSessionHasNoErrors();
    expect($item->fresh()->data_source_type->value)->toBe('MANUAL');
});

test('strategic goals reconcile exactly to 100 and publish only when allocation and objectives match', function (): void {
    $strategic = app(StrategicPlanningService::class);
    $plans = app(PerformancePlanService::class);
    $goalA = $strategic->createGoal(['cycle_id' => $this->cycle->id, 'organization_id' => $this->orgA->id, 'code' => 'SG-A', 'name_en' => 'Digital services', 'name_am' => 'ዲጂታል አገልግሎት', 'weight_percent' => '60.0000', 'is_shared' => true], $this->admin);
    $goalB = $strategic->createGoal(['cycle_id' => $this->cycle->id, 'organization_id' => $this->orgA->id, 'code' => 'SG-B', 'name_en' => 'Workforce capability', 'name_am' => 'የሰው ኃይል አቅም', 'weight_percent' => '40.0000'], $this->admin);
    $strategic->addAllocation($goalA, ['organization_unit_id' => $this->directorate->id, 'organization_contribution_percent' => '35.0000', 'allocation_type' => 'PRIMARY', 'is_lead' => true], $this->admin);
    $strategic->addAllocation($goalA, ['organization_unit_id' => $this->team->id, 'organization_contribution_percent' => '25.0000', 'allocation_type' => 'SHARED', 'is_lead' => false], $this->admin);
    $strategic->addAllocation($goalB, ['organization_unit_id' => $this->team2->id, 'organization_contribution_percent' => '40.0000', 'allocation_type' => 'PRIMARY', 'is_lead' => true], $this->admin);

    $plan = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'ORGANIZATION', 'organization_id' => $this->orgA->id, 'title' => 'Strategic plan'], $this->admin);
    $plans->addObjective($plan, ['strategic_goal_id' => $goalA->id, 'code' => 'OBJ-A', 'title_en' => 'Digitize services', 'weight' => 60, 'absolute_weight_percent' => 60], $this->admin);
    $plans->addObjective($plan, ['strategic_goal_id' => $goalB->id, 'code' => 'OBJ-B', 'title_en' => 'Build capability', 'weight' => 40, 'absolute_weight_percent' => 40], $this->admin);

    expect($strategic->organizationReadiness($this->cycle->id, $this->orgA->id))->toMatchArray(['total' => '100.0000', 'remaining' => '0.0000', 'ready' => true]);
    $strategic->transition($goalA->fresh(), StrategicGoalStatus::UnderReview, $this->admin);
    $strategic->transition($goalA->fresh(), StrategicGoalStatus::Approved, $this->approver);
    $strategic->transition($goalA->fresh(), StrategicGoalStatus::Published, $this->admin);

    expect($goalA->fresh()->status)->toBe(StrategicGoalStatus::Published)
        ->and($goalA->fresh()->published_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event_type', 'strategic_goal_status_changed')->count())->toBe(3);
});

test('strategic allocation rejects a second lead and reports an exact mismatch', function (): void {
    $strategic = app(StrategicPlanningService::class);
    $goal = $strategic->createGoal(['cycle_id' => $this->cycle->id, 'organization_id' => $this->orgA->id, 'code' => 'SG-LEAD', 'name_en' => 'Shared delivery', 'name_am' => 'የጋራ አፈጻጸም', 'weight_percent' => '50.0000'], $this->admin);
    $strategic->addAllocation($goal, ['organization_unit_id' => $this->directorate->id, 'organization_contribution_percent' => '20.0000', 'allocation_type' => 'PRIMARY', 'is_lead' => true], $this->admin);

    expect(fn () => $strategic->addAllocation($goal, ['organization_unit_id' => $this->team->id, 'organization_contribution_percent' => '20.0000', 'allocation_type' => 'SHARED', 'is_lead' => true], $this->admin))
        ->toThrow(ValidationException::class)
        ->and($strategic->readiness($goal)['allocation_total'])->toBe('20.0000')
        ->and($strategic->readiness($goal)['ready'])->toBeFalse();
});

test('quarterly KPI targets keep explicitly entered values without automatic division', function (): void {
    $plans = app(PerformancePlanService::class);
    $plan = $plans->create(['cycle_id' => $this->cycle->id, 'plan_type' => 'ORGANIZATION', 'organization_id' => $this->orgA->id, 'title' => 'Target plan'], $this->admin);
    $objective = $plans->addObjective($plan, ['code' => 'TARGETS', 'title_en' => 'Quarterly delivery', 'weight' => 100], $this->admin);
    $target = $plans->addTarget($objective, ['kpi_id' => $this->kCases->id, 'target_value' => 1000, 'weight' => 100], $this->admin);

    app(StrategicPlanningService::class)->replacePeriodTargets($target, [
        ['period_type' => 'QUARTER', 'period_number' => 1, 'target_value' => '100.0000', 'is_cumulative' => false],
        ['period_type' => 'QUARTER', 'period_number' => 2, 'target_value' => '175.0000', 'is_cumulative' => false],
        ['period_type' => 'QUARTER', 'period_number' => 3, 'target_value' => '300.0000', 'is_cumulative' => false],
        ['period_type' => 'QUARTER', 'period_number' => 4, 'target_value' => '425.0000', 'is_cumulative' => false],
    ], $this->admin);

    expect($target->periodTargets()->pluck('target_value')->all())->toBe(['100.0000', '175.0000', '300.0000', '425.0000']);
});

test('strategic goal page is scoped and renders its readiness ledger', function (): void {
    $this->actingAs($this->admin)->get(route('performance.strategic-goals.index', ['cycle_id' => $this->cycle->id, 'organization_id' => $this->orgA->id]))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Performance/StrategicGoals/Index')->has('goals.data')->has('summary.problems'));

    $this->actingAs($this->adminB)->get(route('performance.strategic-goals.index', ['cycle_id' => $this->cycle->id, 'organization_id' => $this->orgA->id]))->assertForbidden();
});
