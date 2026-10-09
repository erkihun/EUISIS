<?php

declare(strict_types=1);

use App\Models\AssessmentCycle;
use App\Models\AssessmentCycleEligibility;
use App\Models\AssessmentExclusionRequest;
use App\Models\AssessmentForm;
use App\Models\AssessmentFormVersion;
use App\Models\AssessmentInstitutionSubmission;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResultBandPolicy;
use App\Models\AssessmentType;
use App\Models\AssessmentUnassessedReason;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Assessment\Oversight\AssessmentCoverageService;
use App\Services\Assessment\Oversight\AssessmentDataQualityService;
use App\Services\Assessment\Oversight\AssessmentEligibilityService;
use App\Services\Assessment\Oversight\AssessmentOversightQueryService;
use App\Services\Assessment\Oversight\AssessmentResultDistributionService;
use App\Services\Assessment\Oversight\OversightAccess;
use App\Services\Assessment\Oversight\OversightScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| Assessment Oversight & Compliance — docs/assessment-oversight.md
|--------------------------------------------------------------------------
| Synthetic data only. Every figure is computed from records; nothing here
| reproduces the reference paper form or its ranges.
*/

const AO_CITY = [
    'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status',
    'assessment_oversight.view_results', 'assessment_oversight.view_demographics', 'assessment_oversight.view_data_quality',
    'assessment_oversight.manage_cycles', 'assessment_oversight.manage_policies',
    'assessment_exclusions.approve', 'assessment_submissions.review', 'assessment_submissions.return', 'assessment_submissions.verify', 'assessment_submissions.finalize',
    'assessment_reports.view', 'assessment_reports.export',
];
const AO_INSTITUTION = [
    'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status',
    'assessment_oversight.view_results', 'assessment_oversight.view_demographics', 'assessment_oversight.view_data_quality',
    'assessment_exclusions.request', 'assessment_submissions.submit', 'assessment_reports.view', 'assessment_reports.export',
];

test('dashboard evaluator shortages are counted per assessment instead of offset by surplus assignments', function (): void {
    $first = aoEmployee($this, 'PEER-A', $this->orgA, $this->unitA1, 'female');
    $second = aoEmployee($this, 'PEER-B', $this->orgA, $this->unitA1, 'male');
    DB::table('assessment_evaluator_schemes')->where('form_version_id', $this->v1->id)->update(['required_count' => 2]);
    aoSnapshot($this);
    aoRecord($this, $first, 'assigned', responses: 3);
    aoRecord($this, $second, 'assigned', responses: 0);
    $rows = app(AssessmentOversightQueryService::class)
        ->peerCompletion($this->cycle, new OversightScope(null));
    expect($rows[0]['required'])->toBe(4)->and($rows[0]['assigned'])->toBe(4)
        ->and($rows[0]['missing'])->toBe(1)->and($rows[0]['completed'])->toBe(0);
});

test('dashboard aggregate permission does not disclose demographics or data-quality details', function (): void {
    aoEmployee($this, 'DASH-A', $this->orgA, $this->unitA1, 'female');
    aoSnapshot($this);
    $viewer = aoUser(['assessment_oversight.view_dashboard'], $this->orgA);
    $this->actingAs($viewer)->get(route('assessment-oversight.dashboard', ['cycle' => $this->cycle->id]))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('data.totals.eligible', 1)
        ->where('data.totals.gender', null)
        ->where('data.issues', null)
        ->where('data.comparison.0.male_assessed', null)
        ->where('data.comparison.0.female_assessed', null)
        ->where('data.comparison.0.issues', null)
        ->where('data.institutionPage.total', 1));
});

test('dashboard organization filters narrow all totals and reject a forged organization', function (): void {
    aoEmployee($this, 'DASH-A', $this->orgA, $this->unitA1, 'female');
    aoEmployee($this, 'DASH-B', $this->orgB, $this->unitB, 'male');
    aoSnapshot($this);
    $this->actingAs($this->city)->get(route('assessment-oversight.dashboard', ['cycle' => $this->cycle->id, 'organization_id' => $this->orgA->id]))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('data.totals.eligible', 1)->where('data.institutions.expected', 1)
        ->where('data.comparison.0.organization.id', $this->orgA->id)
        ->where('data.units.0.eligible', 1));
    $this->actingAs(aoUser(['assessment_oversight.view_dashboard'], $this->orgA))
        ->get(route('assessment-oversight.dashboard', ['cycle' => $this->cycle->id, 'organization_id' => $this->orgB->id]))->assertForbidden();
});

function aoUser(array $permissions, ?Organization $scope = null, ?Employee $employee = null): User
{
    $role = Role::findOrCreate('AO '.Str::random(6), 'web');
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user = User::factory()->create(['status' => 'active', 'employee_id' => $employee?->id]);
    $user->assignRole($role);
    if ($scope !== null) {
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $scope->id, 'scope_type' => 'self', 'is_active' => true]);
    }
    app(OrganizationScopeService::class)->clearCache();
    app(OversightAccess::class)->forget();

    return $user->fresh();
}

function aoEmployee(object $t, string $number, Organization $org, ?OrganizationUnit $unit, ?string $gender, string $status = 'active', ?Position $position = null, string $from = '2025-01-01'): Employee
{
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => $number, 'last_name' => 'E', 'full_name' => "{$number} E", 'status' => $status, 'gender' => $gender]);
    EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org->id, 'organization_unit_id' => $unit?->id, 'position_id' => $position?->id ?? ($org->is($t->orgA) ? $t->posA->id : $t->posB->id),
        'assignment_status' => 'active', 'effective_from' => $from, 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => EmployeeAssignment::query()->where('employee_id', $employee->id)->value('id')]);

    return $employee;
}

function aoVersion(object $t, string $code, ?Organization $only = null, int $priority = 0, ?AssessmentType $type = null): AssessmentFormVersion
{
    $form = AssessmentForm::query()->forceCreate(['code' => $code, 'name_en' => "Form {$code}", 'assessment_type_id' => ($type ?? $t->type)->id, 'status' => 'active']);
    $version = AssessmentFormVersion::query()->forceCreate(['form_id' => $form->id, 'version_no' => 1, 'status' => 'published', 'name_en' => "Form {$code}", 'scoring_method' => 'percent_of_max', 'max_total_score' => 10, 'published_at' => now()]);
    $form->forceFill(['current_version_id' => $version->id])->save();
    DB::table('assessment_form_target_rules')->insert(['id' => (string) Str::uuid7(), 'form_version_id' => $version->id, 'target_type' => $only ? 'organization' : 'everyone',
        'target_id' => $only?->id, 'effect' => 'include', 'priority' => $priority, 'include_descendants' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('assessment_evaluator_schemes')->insert(['id' => (string) Str::uuid7(), 'form_version_id' => $version->id, 'evaluator_type' => 'peer', 'required_count' => 1,
        'selection_method' => 'admin_selected', 'aggregation_method' => 'average', 'created_at' => now(), 'updated_at' => now()]);

    return $version;
}

/** A record in the test cycle's period; $responses = number of submitted peer responses. */
function aoRecord(object $t, Employee $employee, string $status, ?string $percentage = null, ?AssessmentFormVersion $version = null, int $responses = 1, ?string $reason = null): AssessmentRecord
{
    $org = EmployeeAssignment::query()->where('employee_id', $employee->id)->where('is_current', true)->value('organization_id');
    $record = AssessmentRecord::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org, 'assessment_type_id' => $t->type->id, 'form_version_id' => ($version ?? $t->v1)->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'employee_snapshot' => ['name' => $employee->full_name],
        'status' => $status, 'created_by' => $t->city->id, 'reviewer_id' => $t->city->id, 'percentage' => $percentage, 'unassessed_reason' => $reason,
    ]);
    for ($i = 0; $i < max(1, $responses); $i++) {
        $peer = User::factory()->create(['status' => 'active']);
        $record->responses()->create(['evaluator_id' => $peer->id, 'submitted_at' => $i < $responses && $status !== 'assigned' ? now() : null]);
    }

    return $record;
}

function aoPolicy(array $bands, string $code = 'BANDS'): AssessmentResultBandPolicy
{
    $policy = AssessmentResultBandPolicy::query()->create(['code' => $code, 'version_no' => (int) AssessmentResultBandPolicy::query()->where('code', $code)->max('version_no') + 1, 'name_en' => 'Bands', 'status' => 'draft', 'range_min' => 0, 'range_max' => 100, 'requires_full_coverage' => true]);
    foreach ($bands as $i => [$c, $min, $max, $maxInclusive]) {
        $policy->bands()->create(['code' => $c, 'label_en' => $c, 'min_score' => $min, 'max_score' => $max, 'min_inclusive' => true, 'max_inclusive' => $maxInclusive, 'sort_order' => $i]);
    }

    return $policy;
}

function aoSnapshot(object $t, bool $finalize = true): void
{
    $service = app(AssessmentEligibilityService::class);
    $service->snapshotCycleEligibility($t->city, $t->cycle->fresh());
    if ($finalize) {
        $service->finalize($t->city, $t->cycle->fresh());
    }
    $t->cycle->refresh();
}

function aoTotals(object $t, ?OversightScope $scope = null): array
{
    return app(AssessmentCoverageService::class)->totals($t->cycle->fresh(), $scope ?? new OversightScope(null));
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-10 10:00:00');
    $type = OrganizationType::query()->create(['code' => 'AO-T', 'name_en' => 'Bureau']);
    $this->orgA = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AO-A', 'name_en' => 'Alpha Bureau', 'status' => 'active']);
    $this->orgB = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AO-B', 'name_en' => 'Beta Bureau', 'status' => 'active']);
    $this->orgC = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AO-C', 'name_en' => 'Gamma Bureau', 'status' => 'active']);
    $this->unitA1 = OrganizationUnit::query()->create(['organization_id' => $this->orgA->id, 'code' => 'AO-A1', 'name_en' => 'A One', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->unitA2 = OrganizationUnit::query()->create(['organization_id' => $this->orgA->id, 'code' => 'AO-A2', 'name_en' => 'A Two', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->unitB = OrganizationUnit::query()->create(['organization_id' => $this->orgB->id, 'code' => 'AO-B1', 'name_en' => 'B One', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->posA = Position::query()->create(['organization_id' => $this->orgA->id, 'organization_unit_id' => $this->unitA1->id, 'job_position_code' => 'AO-PA', 'title_en' => 'Officer A', 'is_active' => true]);
    $this->posB = Position::query()->create(['organization_id' => $this->orgB->id, 'organization_unit_id' => $this->unitB->id, 'job_position_code' => 'AO-PB', 'title_en' => 'Officer B', 'is_active' => true]);
    $this->type = AssessmentType::query()->where('code', 'BEHAVIORAL_COMPETENCY')->firstOrFail();
    $this->city = aoUser(AO_CITY);
    $this->reviewer = aoUser(AO_CITY);
    $this->v1 = aoVersion($this, 'AO-F1');
    $this->cycle = AssessmentCycle::query()->create([
        'code' => 'AO-2026H1', 'name_en' => '2026 first half', 'assessment_type_id' => $this->type->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'reference_date' => '2026-06-30',
        'status' => 'active', 'eligibility_status' => 'open', 'population_rule' => 'all_assigned',
    ]);
    foreach ([$this->orgA, $this->orgB] as $org) {
        $this->cycle->organizations()->create(['organization_id' => $org->id, 'status' => 'included', 'included_at' => now()]);
    }
});

afterEach(fn () => Carbon::setTestNow());

// ── Eligibility ─────────────────────────────────────────────────────────────

test('1-2. only employees of participating institutions are in the population; valid targets are eligible', function (): void {
    aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoEmployee($this, 'B-1', $this->orgB, $this->unitB, 'female');
    aoEmployee($this, 'C-1', $this->orgC, null, 'male');
    aoSnapshot($this);

    $rows = AssessmentCycleEligibility::query()->where('assessment_cycle_id', $this->cycle->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('organization_id')->unique()->sort()->values()->all())->toBe(collect([$this->orgA->id, $this->orgB->id])->sort()->values()->all())
        ->and($rows->every(fn ($r) => $r->eligibility_status === 'eligible' && $r->form_resolution === 'matched'))->toBeTrue();
});

test('3. configured rules exclude with a system reason: employment status, minimum service, target rules', function (): void {
    aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    $suspended = aoEmployee($this, 'A-2', $this->orgA, $this->unitA1, 'male', 'suspended');
    $newHire = aoEmployee($this, 'A-3', $this->orgA, $this->unitA1, 'female', 'active', null, '2026-06-01');
    $this->cycle->update(['min_service_days' => 90]);
    aoSnapshot($this, false);

    $byEmployee = AssessmentCycleEligibility::query()->with('reason')->get()->keyBy('employee_id');
    expect($byEmployee[$suspended->id]->eligibility_status)->toBe('excluded')->and($byEmployee[$suspended->id]->reason->code)->toBe('employment_status')
        ->and($byEmployee[$newHire->id]->reason->code)->toBe('new_employee')->and($byEmployee[$newHire->id]->reason_source)->toBe('system_detected');

    // A type with no forms: under target_rules nobody is targeted, so nobody is eligible.
    $leadership = AssessmentType::query()->where('code', 'LEADERSHIP')->firstOrFail();
    $this->cycle->update(['assessment_type_id' => $leadership->id, 'population_rule' => 'target_rules', 'min_service_days' => null]);
    aoSnapshot($this, false);
    expect(AssessmentCycleEligibility::query()->where('eligibility_status', 'eligible')->count())->toBe(0);
    $this->cycle->update(['population_rule' => 'all_assigned']);
    aoSnapshot($this, false);
    expect(AssessmentCycleEligibility::query()->where('eligibility_status', 'eligible')->where('form_resolution', 'no_applicable_form')->count())->toBe(2);
});

test('4-5. a transfer after the snapshot does not move the employee; snapshot and finalization are audited', function (): void {
    $employee = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoSnapshot($this);
    EmployeeAssignment::query()->where('employee_id', $employee->id)->update(['effective_to' => '2026-05-31', 'is_current' => false, 'assignment_status' => 'closed']);
    EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $this->orgB->id, 'organization_unit_id' => $this->unitB->id, 'position_id' => $this->posB->id, 'assignment_status' => 'active', 'effective_from' => '2026-06-01', 'is_current' => true]);

    expect(AssessmentCycleEligibility::query()->where('employee_id', $employee->id)->value('organization_id'))->toBe($this->orgA->id);
    $explain = app(AssessmentEligibilityService::class)->explainEligibility($this->cycle, $employee);
    expect($explain['drifted'])->toBeTrue()->and($explain['live']['organization_id'])->toBe($this->orgB->id);
    expect(fn () => app(AssessmentEligibilityService::class)->snapshotCycleEligibility($this->city, $this->cycle))->toThrow(ValidationException::class);
    expect(AuditLog::query()->where('event_type', 'assessment_oversight.eligibility_snapshot_created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'assessment_oversight.eligibility_finalized')->exists())->toBeTrue();
});

test('6. exclusions need the request permission, scope, a second approver and are audited', function (): void {
    $employee = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoSnapshot($this);
    $row = AssessmentCycleEligibility::query()->where('employee_id', $employee->id)->firstOrFail();
    $reason = AssessmentUnassessedReason::query()->create(['code' => 'approved_leave', 'name_en' => 'Approved leave', 'source' => 'approved_exception', 'requires_approval' => true, 'excludes_from_denominator' => true, 'is_active' => true]);
    $payload = ['eligibility_id' => $row->id, 'reason_id' => $reason->id, 'note' => 'On approved leave'];

    $this->actingAs(aoUser(['assessment_oversight.view_dashboard'], $this->orgA))->post(route('assessment-oversight.exclusions.store', $this->cycle), $payload)->assertForbidden();
    $this->actingAs(aoUser(AO_INSTITUTION, $this->orgB))->post(route('assessment-oversight.exclusions.store', $this->cycle), $payload)->assertForbidden();
    $requester = aoUser([...AO_INSTITUTION, 'assessment_exclusions.approve'], $this->orgA);
    $this->actingAs($requester)->post(route('assessment-oversight.exclusions.store', $this->cycle), $payload)->assertSessionHasNoErrors();
    $request = AssessmentExclusionRequest::query()->firstOrFail();
    $this->actingAs($requester)->post(route('assessment-oversight.exclusions.decide', $request), ['approve' => true])->assertForbidden();
    expect(aoTotals($this)['eligible'])->toBe(1);

    $this->actingAs($this->city)->post(route('assessment-oversight.exclusions.decide', $request), ['approve' => true])->assertSessionHasNoErrors();
    expect(AuditLog::query()->where('event_type', 'assessment_oversight.exclusion_requested')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'assessment_oversight.exclusion_approved')->exists())->toBeTrue();
});

// ── Coverage ────────────────────────────────────────────────────────────────

test('7-11. assessed means a finalized result; drafts and submitted work are unassessed; coverage = assessed / eligible', function (): void {
    $e = collect(range(1, 5))->map(fn ($i) => aoEmployee($this, "A-{$i}", $this->orgA, $this->unitA1, $i % 2 ? 'male' : 'female'));
    aoSnapshot($this);
    aoRecord($this, $e[0], 'reviewed', '91.5000');
    aoRecord($this, $e[1], 'acknowledged', '72.0000');
    aoRecord($this, $e[2], 'assigned', null, null, 0);
    aoRecord($this, $e[3], 'submitted', '80.0000');

    $t = aoTotals($this);
    expect($t['eligible'])->toBe(5)->and($t['assessed'])->toBe(2)->and($t['unassessed'])->toBe(3)
        ->and($t['coverage_percent'])->toBe('40.00')
        ->and($t['outcomes']['not_started'])->toBe(1)->and($t['outcomes']['awaiting_review'])->toBe(1)->and($t['outcomes']['not_assigned'])->toBe(1);
});

test('12. an approved exclusion stays in the denominator unless the cycle policy and the reason both say otherwise', function (): void {
    $e = collect(range(1, 4))->map(fn ($i) => aoEmployee($this, "A-{$i}", $this->orgA, $this->unitA1, 'male'));
    aoSnapshot($this);
    aoRecord($this, $e[0], 'reviewed', '90');
    $reason = AssessmentUnassessedReason::query()->create(['code' => 'long_leave', 'name_en' => 'Long leave', 'source' => 'approved_exception', 'requires_approval' => true, 'excludes_from_denominator' => true, 'is_active' => true]);
    $service = app(AssessmentEligibilityService::class);
    $requester = aoUser(AO_INSTITUTION, $this->orgA);
    $exclude = function (Employee $employee) use ($service, $requester, $reason): void {
        $row = AssessmentCycleEligibility::query()->where('employee_id', $employee->id)->firstOrFail();
        $service->decide($this->city, $service->requestExclusion($requester, $row, $reason, 'leave'), true, null);
    };

    $exclude($e[1]); // policy undecided (NULL): no denominator change
    expect(aoTotals($this))->toMatchArray(['eligible' => 4, 'assessed' => 1, 'coverage_percent' => '25.00'])
        ->and(aoTotals($this)['outcomes']['approved_exception'])->toBe(1);

    $this->cycle->forceFill(['exclusion_reduces_denominator' => true])->save();
    $exclude($e[2]);
    $t = aoTotals($this);
    expect($t['eligible'])->toBe(3)->and($t['coverage_percent'])->toBe('33.33')->and($t['gross_coverage_percent'])->toBe('25.00');
});

// ── Gender ──────────────────────────────────────────────────────────────────

test('13-16. gender comes from employee master data; unknown is reported separately and totals reconcile', function (): void {
    $m1 = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'Male');
    $f1 = aoEmployee($this, 'A-2', $this->orgA, $this->unitA1, 'female');
    $f2 = aoEmployee($this, 'A-3', $this->orgA, $this->unitA1, 'F');
    $u1 = aoEmployee($this, 'A-4', $this->orgA, $this->unitA1, null);
    aoSnapshot($this);
    foreach ([$m1, $f1, $u1] as $employee) {
        aoRecord($this, $employee, 'reviewed', '75');
    }

    $t = aoTotals($this);
    expect($t['gender']['male'])->toMatchArray(['eligible' => 1, 'assessed' => 1])
        ->and($t['gender']['female'])->toMatchArray(['eligible' => 2, 'assessed' => 1, 'coverage_percent' => '50.00'])
        ->and($t['gender']['unknown'])->toMatchArray(['eligible' => 1, 'assessed' => 1]);
    expect(collect(AssessmentCoverageService::reconcile($t))->every('ok'))->toBeTrue();
});

test('17. organization scope limits every aggregate', function (): void {
    aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoEmployee($this, 'B-1', $this->orgB, $this->unitB, 'female');
    aoSnapshot($this);
    $institution = aoUser(AO_INSTITUTION, $this->orgA);

    $scope = app(OversightAccess::class)->scopeFor($institution);
    expect(aoTotals($this, $scope)['eligible'])->toBe(1)->and(aoTotals($this)['eligible'])->toBe(2);
});

// ── Result distribution ────────────────────────────────────────────────────

test('18-21. configured bands classify, overlaps and gaps are rejected, history keeps its policy, totals reconcile', function (): void {
    $service = app(AssessmentResultDistributionService::class);
    $overlap = aoPolicy([['LOW', 0, 60, true], ['HIGH', 59, 100, true]], 'OVL');
    expect($service->validate($overlap))->not->toBeEmpty();
    expect(fn () => $service->activate($this->city, $overlap))->toThrow(ValidationException::class);
    $gap = aoPolicy([['LOW', 0, 50, false], ['HIGH', 60, 100, true]], 'GAP');
    expect(collect($service->validate($gap))->contains(fn ($p) => str_contains($p, 'gap')))->toBeTrue();

    $policy = aoPolicy([['LOW', 0, 60, false], ['MID', 60, 80, false], ['TOP', 80, 100, true]]);
    $service->activate($this->city, $policy);
    $this->cycle->update(['result_band_policy_id' => $policy->id]);
    $e = collect(range(1, 4))->map(fn ($i) => aoEmployee($this, "A-{$i}", $this->orgA, $this->unitA1, 'male'));
    aoSnapshot($this);
    foreach (['59.9999', '60', '80', '100'] as $i => $score) {
        aoRecord($this, $e[$i], 'reviewed', $score);
    }

    $d = $service->distribution($this->cycle->fresh(), new OversightScope(null));
    expect(collect($d['bands'])->pluck('count', 'code')->all())->toBe(['LOW' => 1, 'MID' => 1, 'TOP' => 2])
        ->and($d['classified'])->toBe($d['assessed']);

    // A new version with different ranges does not reclassify this cycle.
    $next = $service->newVersion($this->city, $policy->fresh());
    $next->bands()->where('code', 'TOP')->update(['min_score' => 90]);
    $next->bands()->where('code', 'MID')->update(['max_score' => 90]);
    $service->activate($this->city, $next->fresh());
    expect($policy->fresh()->status)->toBe('retired');
    $again = $service->distribution($this->cycle->fresh(), new OversightScope(null));
    expect(collect($again['bands'])->pluck('count', 'code')->all())->toBe(['LOW' => 1, 'MID' => 1, 'TOP' => 2]);
});

// ── Institution monitoring ─────────────────────────────────────────────────

test('22-25. city sees every participating institution, institutions their own, units their unit; drill-down is enforced', function (): void {
    aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoEmployee($this, 'A-2', $this->orgA, $this->unitA2, 'female');
    aoEmployee($this, 'B-1', $this->orgB, $this->unitB, 'female');
    aoSnapshot($this);
    $query = ['cycle' => $this->cycle->id];

    $this->actingAs($this->city)->get(route('assessment-oversight.institutions', $query))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Assessments/Oversight/Institutions')->has('rows.data', 2));
    $institution = aoUser(AO_INSTITUTION, $this->orgA);
    $this->actingAs($institution)->get(route('assessment-oversight.institutions', $query))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rows.data', 1)->where('rows.data.0.organization.id', $this->orgA->id));
    $this->actingAs($institution)->get(route('assessment-oversight.institution', ['organization' => $this->orgB->id] + $query))->assertForbidden();
    $this->actingAs($institution)->get(route('assessment-oversight.employees', ['organization_id' => $this->orgB->id] + $query))->assertForbidden();

    $managerEmployee = Employee::query()->where('employee_number', 'A-1')->firstOrFail();
    $manager = aoUser(['assessment_oversight.view_unit', 'assessment_oversight.view_employee_status'], null, $managerEmployee);
    $this->actingAs($manager)->get(route('assessment-oversight.employees', $query))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Assessments/Oversight/Employees')->has('rows.data', 1)->where('rows.data.0.employee_number', 'A-1'));
    $this->actingAs($manager)->get(route('assessment-oversight.institutions', $query))->assertForbidden();
});

// ── Submission ──────────────────────────────────────────────────────────────

test('26-33. readiness blocks incomplete data; submit, return with reason, resubmit, verify, finalize; finalized is immutable', function (): void {
    $e1 = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    $e2 = aoEmployee($this, 'A-2', $this->orgA, $this->unitA1, 'female');
    aoSnapshot($this);
    aoRecord($this, $e1, 'reviewed', '88');
    $institution = aoUser(AO_INSTITUTION, $this->orgA);
    $submit = fn () => $this->actingAs($institution)->post(route('assessment-oversight.submissions.store', $this->cycle), ['organization_id' => $this->orgA->id, 'note' => 'Signed off']);

    $submit()->assertSessionHasErrors('submission'); // e2 has no assessment yet (MISSING_ASSESSMENT_ASSIGNMENT)
    aoRecord($this, $e2, 'unassessed', null, null, 0, 'illness');
    $submit()->assertSessionHasNoErrors();

    $submission = AssessmentInstitutionSubmission::query()->firstOrFail();
    expect($submission->only(['status', 'eligible_count', 'assessed_count', 'unassessed_count', 'male_assessed', 'female_eligible']))
        ->toBe(['status' => 'submitted', 'eligible_count' => 2, 'assessed_count' => 1, 'unassessed_count' => 1, 'male_assessed' => 1, 'female_eligible' => 1])
        ->and((string) $submission->coverage_percent)->toBe('50.0000');

    $this->actingAs($institution)->post(route('assessment-oversight.submissions.move', [$submission, 'verify']))->assertForbidden();
    $this->actingAs($this->reviewer)->post(route('assessment-oversight.submissions.move', [$submission, 'return']), [])->assertSessionHasErrors('comment');
    $this->actingAs($this->reviewer)->post(route('assessment-oversight.submissions.move', [$submission, 'return']), ['comment' => 'Check unit totals'])->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe('returned');

    $submit()->assertSessionHasNoErrors();
    $second = AssessmentInstitutionSubmission::query()->where('revision_no', 2)->firstOrFail();
    $this->actingAs($this->reviewer)->post(route('assessment-oversight.submissions.move', [$second, 'verify']))->assertSessionHasNoErrors();
    $this->actingAs($this->reviewer)->post(route('assessment-oversight.submissions.move', [$second, 'finalize']))->assertForbidden();
    $this->actingAs($this->city)->post(route('assessment-oversight.submissions.move', [$second, 'finalize']))->assertSessionHasNoErrors();
    expect($second->fresh()->status)->toBe('finalized');
    $this->actingAs($this->reviewer)->post(route('assessment-oversight.submissions.move', [$second, 'return']), ['comment' => 'late'])->assertSessionHasErrors('submission');
    expect(AuditLog::query()->whereIn('event_type', ['assessment_submissions.submitted', 'assessment_submissions.returned', 'assessment_submissions.verified', 'assessment_submissions.finalized'])->count())->toBe(5);
});

test('39. changed data after submission makes the summary outdated instead of silently changing totals', function (): void {
    $e1 = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoSnapshot($this);
    $record = aoRecord($this, $e1, 'reviewed', '70');
    $institution = aoUser(AO_INSTITUTION, $this->orgA);
    $this->actingAs($institution)->post(route('assessment-oversight.submissions.store', $this->cycle), ['organization_id' => $this->orgA->id])->assertSessionHasNoErrors();
    $submission = AssessmentInstitutionSubmission::query()->firstOrFail();

    Carbon::setTestNow('2026-07-11 09:00:00');
    $record->update(['percentage' => '95']);
    $this->actingAs($this->reviewer)->post(route('assessment-oversight.submissions.move', [$submission, 'verify']))->assertSessionHasErrors('submission');
    expect($submission->fresh()->status)->toBe('outdated')->and($submission->fresh()->assessed_count)->toBe(1);
});

test('city review is an auditable transition and verification reminders respect the configured deadline', function (): void {
    $employee = aoEmployee($this, 'REVIEW-1', $this->orgA, $this->unitA1, 'male');
    aoSnapshot($this);
    aoRecord($this, $employee, 'reviewed', '75');
    $institution = aoUser(AO_INSTITUTION, $this->orgA);
    $this->actingAs($institution)->post(route('assessment-oversight.submissions.store', $this->cycle), ['organization_id' => $this->orgA->id])->assertSessionHasNoErrors();
    $submission = AssessmentInstitutionSubmission::query()->firstOrFail();

    $this->actingAs($this->reviewer)->post(route('assessment-oversight.submissions.move', [$submission, 'start-review']), ['comment' => 'Review opened'])->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe('under_city_review')
        ->and($submission->fresh()->city_reviewer_id)->toBe($this->reviewer->id)
        ->and($submission->fresh()->review_started_at)->not->toBeNull();

    $this->cycle->update(['verification_deadline' => '2026-07-05']);
    Carbon::setTestNow('2026-07-11 08:00:00');
    $this->artisan('assessments:oversight-monitor')->assertSuccessful();
    expect($this->reviewer->notifications()->get()->filter(fn ($notification): bool => ($notification->data['kind'] ?? null) === 'assessment_verification_overdue')->count())->toBe(1);
});

// ── Data quality ────────────────────────────────────────────────────────────

test('34-40. data-quality rules detect real problems; only blocking ones stop a submission', function (): void {
    $vB = aoVersion($this, 'AO-FB', $this->orgB, 10);
    $noPosition = Employee::query()->create(['employee_number' => 'A-9', 'first_name' => 'A', 'last_name' => 'Nine', 'full_name' => 'A Nine', 'status' => 'active', 'gender' => 'male']);
    EmployeeAssignment::query()->create(['employee_id' => $noPosition->id, 'organization_id' => $this->orgA->id, 'assignment_status' => 'active', 'effective_from' => '2025-01-01', 'is_current' => true]);
    $a1 = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, null);
    $b1 = aoEmployee($this, 'B-1', $this->orgB, $this->unitB, 'female');
    $b2 = aoEmployee($this, 'B-2', $this->orgB, $this->unitB, 'male');
    aoSnapshot($this);

    aoRecord($this, $a1, 'reviewed', '80');
    aoRecord($this, $noPosition, 'reviewed', '80');
    aoRecord($this, $b1, 'reviewed', '80', $this->v1); // expected the org-B form
    $missing = aoRecord($this, $b2, 'assigned', null, $vB, 0);
    $missing->responses()->delete();
    // A second record for the same employee in the cycle.
    AssessmentRecord::query()->create(['assessment_cycle_id' => $this->cycle->id, 'employee_id' => $b2->id, 'organization_id' => $this->orgB->id, 'assessment_type_id' => $this->type->id,
        'form_version_id' => $vB->id, 'period_start' => '2026-01-01', 'period_end' => '2026-03-31', 'employee_snapshot' => [], 'status' => 'assigned', 'created_by' => $this->city->id, 'reviewer_id' => $this->city->id]);

    $rules = app(AssessmentDataQualityService::class)->summary($this->cycle->fresh(), new OversightScope(null))['rules'];
    expect($rules['FORM_ASSIGNMENT_MISMATCH']['count'])->toBe(1)
        ->and($rules['MISSING_EVALUATOR']['count'])->toBeGreaterThanOrEqual(1)
        ->and($rules['DUPLICATE_ASSESSMENT']['count'])->toBe(2)
        ->and($rules['MISSING_POSITION']['count'])->toBe(1)
        ->and($rules['MISSING_GENDER']['count'])->toBe(1)
        ->and($rules['MISSING_GENDER']['severity'])->toBe('warning');

    // Org A: only warnings (missing gender, missing position) → may submit.
    $institutionA = aoUser(AO_INSTITUTION, $this->orgA);
    $this->actingAs($institutionA)->post(route('assessment-oversight.submissions.store', $this->cycle), ['organization_id' => $this->orgA->id])->assertSessionHasNoErrors();
    // Org B: blocking issues → refused.
    $institutionB = aoUser(AO_INSTITUTION, $this->orgB);
    $this->actingAs($institutionB)->post(route('assessment-oversight.submissions.store', $this->cycle), ['organization_id' => $this->orgB->id])->assertSessionHasErrors('submission');

    // No applicable form under all_assigned is a blocking issue.
    $leadership = AssessmentType::query()->where('code', 'LEADERSHIP')->firstOrFail();
    $other = AssessmentCycle::query()->create(['code' => 'AO-L', 'name_en' => 'Leadership', 'assessment_type_id' => $leadership->id, 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
        'reference_date' => '2026-06-30', 'status' => 'active', 'eligibility_status' => 'open', 'population_rule' => 'all_assigned']);
    $other->organizations()->create(['organization_id' => $this->orgA->id, 'status' => 'included', 'included_at' => now()]);
    app(AssessmentEligibilityService::class)->snapshotCycleEligibility($this->city, $other);
    expect(app(AssessmentDataQualityService::class)->summary($other->fresh(), new OversightScope(null))['rules']['NO_APPLICABLE_FORM']['count'])->toBe(2);
});

// ── Security ────────────────────────────────────────────────────────────────

test('41-45. IDOR, result visibility, aggregate-only access, export scope and forged submissions are blocked', function (): void {
    $a1 = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoEmployee($this, 'B-1', $this->orgB, $this->unitB, 'female');
    aoSnapshot($this);
    $record = aoRecord($this, $a1, 'reviewed', '81.2500');
    $query = ['cycle' => $this->cycle->id];
    $institution = aoUser(AO_INSTITUTION, $this->orgA);

    // 41: submitting for another institution.
    $this->actingAs($institution)->post(route('assessment-oversight.submissions.store', $this->cycle), ['organization_id' => $this->orgB->id])->assertForbidden();
    // 42: results only with view_results.
    $statusOnly = aoUser(['assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status'], $this->orgA);
    $this->actingAs($statusOnly)->get(route('assessment-oversight.employees', $query))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.data.0.percentage', null));
    $this->actingAs($institution)->get(route('assessment-oversight.employees', $query))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.data.0.percentage', '81.2500'));
    // 43: aggregate-only users see neither employee lists nor criterion responses.
    $viewer = aoUser(['assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_reports.view']);
    $this->actingAs($viewer)->get(route('assessment-oversight.employees', $query))->assertForbidden();
    $this->actingAs($viewer)->get(route('assessment-records.show', $record))->assertForbidden();
    // 44: export scope.
    $this->actingAs($institution)->get(route('assessment-oversight.export', ['report' => 'unassessed', 'format' => 'csv', 'organization_id' => $this->orgB->id] + $query))->assertForbidden();
    $response = $this->actingAs($institution)->get(route('assessment-oversight.export', ['report' => 'consolidated', 'format' => 'csv'] + $query));
    $response->assertOk();
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
    $csv = $response->streamedContent();
    expect($csv)->toContain('Alpha Bureau')->not->toContain('Beta Bureau');
    expect(AuditLog::query()->where('event_type', 'assessment_reports.exported')->exists())->toBeTrue();
    // 45: an institution cannot verify by forging the endpoint.
    $this->actingAs($institution)->post(route('assessment-oversight.submissions.store', $this->cycle), ['organization_id' => $this->orgA->id]);
    $submission = AssessmentInstitutionSubmission::query()->first();
    if ($submission) {
        $this->actingAs($institution)->post(route('assessment-oversight.submissions.move', [$submission, 'finalize']))->assertForbidden();
    }
});

test('58. CSV exports neutralize formula injection', function (): void {
    $a1 = aoEmployee($this, '=HYPERLINK("x")', $this->orgA, $this->unitA1, 'male');
    aoSnapshot($this);
    $csv = $this->actingAs($this->city)->get(route('assessment-oversight.export', ['report' => 'unassessed', 'format' => 'csv', 'cycle' => $this->cycle->id]))->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toMatch('/(^|,)"?=HYPERLINK/m');
});

// ── Scale / query safety ───────────────────────────────────────────────────

test('46-50. lists are paginated and aggregates do not grow queries with the number of employees', function (): void {
    foreach (range(1, 6) as $i) {
        aoEmployee($this, "A-{$i}", $this->orgA, $this->unitA1, 'male');
    }
    aoSnapshot($this);
    $query = ['cycle' => $this->cycle->id];
    $count = function () use ($query): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->city)->get(route('assessment-oversight.dashboard', $query))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $count(); // warm one-time framework lookups
    $small = $count();
    foreach (range(7, 30) as $i) {
        $employee = aoEmployee($this, "A-{$i}", $this->orgA, $this->unitA1, 'female');
        DB::table('assessment_cycle_employee_eligibility')->insert(['id' => (string) Str::uuid7(), 'assessment_cycle_id' => $this->cycle->id, 'employee_id' => $employee->id,
            'organization_id' => $this->orgA->id, 'organization_unit_id' => $this->unitA1->id, 'position_id' => $this->posA->id, 'eligibility_status' => 'eligible', 'form_resolution' => 'matched',
            'expected_form_version_id' => $this->v1->id, 'snapshot_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    expect($count())->toBe($small);

    $this->actingAs($this->city)->get(route('assessment-oversight.employees', $query))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.per_page', 25)->where('rows.total', 30)->has('rows.data', 25));
    $this->actingAs($this->city)->get(route('assessment-oversight.institutions', $query))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.per_page', 25)->has('rows.data', 2));
});

test('65-66. the scheduled monitor marks outdated submissions and reminds overdue institutions once a day', function (): void {
    $e1 = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoEmployee($this, 'B-1', $this->orgB, $this->unitB, 'female');
    aoSnapshot($this);
    $record = aoRecord($this, $e1, 'reviewed', '70');
    $institutionA = aoUser(AO_INSTITUTION, $this->orgA);
    $institutionB = aoUser(AO_INSTITUTION, $this->orgB);
    $this->actingAs($institutionA)->post(route('assessment-oversight.submissions.store', $this->cycle), ['organization_id' => $this->orgA->id])->assertSessionHasNoErrors();
    $this->cycle->update(['submission_deadline' => '2026-07-05']);

    Carbon::setTestNow('2026-07-11 08:00:00');
    $record->update(['percentage' => '71']);
    $this->artisan('assessments:oversight-monitor')->assertSuccessful();
    $this->artisan('assessments:oversight-monitor')->assertSuccessful();

    expect(AssessmentInstitutionSubmission::query()->first()->status)->toBe('outdated')
        ->and($institutionB->notifications()->get()->filter(fn ($notification): bool => ($notification->data['kind'] ?? null) === 'assessment_submission_overdue')->count())->toBe(1)
        ->and($institutionA->notifications()->get()->filter(fn ($notification): bool => ($notification->data['kind'] ?? null) === 'assessment_submission_overdue')->count())->toBe(1);
});

test('every oversight page renders for a city user with real data', function (): void {
    $policy = aoPolicy([['LOW', 0, 60, false], ['TOP', 60, 100, true]]);
    app(AssessmentResultDistributionService::class)->activate($this->city, $policy);
    $this->cycle->update(['result_band_policy_id' => $policy->id, 'submission_deadline' => '2026-08-01', 'small_group_threshold' => 2]);
    $e1 = aoEmployee($this, 'A-1', $this->orgA, $this->unitA1, 'male');
    aoEmployee($this, 'A-2', $this->orgA, $this->unitA2, 'female');
    aoEmployee($this, 'B-1', $this->orgB, $this->unitB, null);
    aoSnapshot($this);
    aoRecord($this, $e1, 'reviewed', '75');
    $q = ['cycle' => $this->cycle->id];

    foreach ([
        ['assessment-oversight.dashboard', $q, 'Dashboard'],
        ['assessment-oversight.institutions', $q + ['sort' => 'coverage', 'direction' => 'desc'], 'Institutions'],
        ['assessment-oversight.institution', $q + ['organization' => $this->orgA->id], 'Institution'],
        ['assessment-oversight.employees', $q + ['outcome' => 'unassessed', 'gender' => 'female'], 'Employees'],
        ['assessment-oversight.distribution', $q + ['breakdown' => 'organization_id'], 'Distribution'],
        ['assessment-oversight.gender', $q, 'Gender'],
        ['assessment-oversight.data-quality', $q + ['code' => 'MISSING_ASSESSMENT_ASSIGNMENT'], 'DataQuality'],
        ['assessment-oversight.submissions', $q, 'Submissions'],
        ['assessment-oversight.reports', $q, 'Reports'],
        ['assessment-oversight.setup', [], 'Setup'],
        ['assessment-oversight.cycles.show', ['cycle' => $this->cycle->id], 'Cycle'],
    ] as [$name, $params, $component]) {
        $this->actingAs($this->city)->get(route($name, $params))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Assessments/Oversight/'.$component));
    }

    // Small-group suppression hides single-person gender cells.
    $this->actingAs($this->city)->get(route('assessment-oversight.institution', $q + ['organization' => $this->orgA->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.gender.male.suppressed', true));
    foreach (['consolidated', 'distribution', 'gender', 'data_quality', 'submissions'] as $report) {
        foreach (['xlsx', 'pdf'] as $format) {
            $this->actingAs($this->city)->get(route('assessment-oversight.export', $q + ['report' => $report, 'format' => $format]))->assertOk();
        }
    }
});

test('reports use the selected institution scope for summary figures and reject a forged institution filter', function (): void {
    $employeeA = aoEmployee($this, 'REPORT-A', $this->orgA, $this->unitA1, 'female');
    $employeeB = aoEmployee($this, 'REPORT-B', $this->orgB, $this->unitB, 'male');
    aoSnapshot($this);
    aoRecord($this, $employeeA, 'reviewed', '75');
    aoRecord($this, $employeeB, 'assigned');

    $this->actingAs($this->city)->get(route('assessment-oversight.reports', [
        'cycle' => $this->cycle->id, 'organization_id' => $this->orgA->id,
    ]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('totals.eligible', 1)
        ->where('totals.assessed', 1)
        ->where('summary.institutions.expected', 1)
        ->where('organizations.0.id', $this->orgA->id));

    $this->actingAs(aoUser(['assessment_reports.view'], $this->orgA))
        ->get(route('assessment-oversight.reports', ['cycle' => $this->cycle->id, 'organization_id' => $this->orgB->id]))
        ->assertForbidden();
});

test('assessment pages live under /assessments and the old /performance URLs redirect permanently', function (): void {
    expect(route('assessment-forms.index', [], false))->toBe('/assessments/forms')
        ->and(route('assessment-records.index', [], false))->toBe('/assessments/records')
        ->and(route('assessment-oversight.dashboard', [], false))->toBe('/assessments/oversight');

    $this->actingAs($this->city)->get('/performance/assessment-oversight/institutions?cycle='.$this->cycle->id)
        ->assertStatus(301)->assertRedirect('/assessments/oversight/institutions?cycle='.$this->cycle->id);
    $this->actingAs($this->city)->get('/performance/assessment-forms')->assertStatus(301)->assertRedirect('/assessments/forms');
    $this->actingAs($this->city)->get('/performance/assessments')->assertStatus(301)->assertRedirect('/assessments/records');
});
