<?php

declare(strict_types=1);

use App\Models\AssessmentCycle;
use App\Models\AssessmentForm;
use App\Models\AssessmentInstitutionSubmission;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResultBandPolicy;
use App\Models\AssessmentType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Assessment\Oversight\OversightAccess;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Assessment Management, end to end, through HTTP only
|--------------------------------------------------------------------------
| Form Builder → oversight cycle → assignment → peer rating → review →
| oversight figures → institution sign-off → city verification → final.
| Synthetic data; nothing from the reference paper form.
*/

function e2eUser(array $permissions, ?Organization $scope = null, ?Employee $employee = null): User
{
    $role = Role::findOrCreate('E2E '.Str::random(6), 'web');
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

function e2eEmployee(string $number, Organization $org, OrganizationUnit $unit, Position $position, string $gender): Employee
{
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => $number, 'last_name' => 'E', 'full_name' => "{$number} E", 'status' => 'active', 'gender' => $gender]);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id,
        'assignment_status' => 'active', 'effective_from' => '2025-01-01', 'is_current' => true]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee;
}

test('a peer assessment flows from the form builder to a finalized institutional summary', function (): void {
    Carbon::setTestNow('2026-07-10 09:00:00');
    $type = OrganizationType::query()->create(['code' => 'E2E-T', 'name_en' => 'Bureau']);
    $org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'E2E-ORG', 'name_en' => 'Civil Service Bureau', 'status' => 'active']);
    $unit = OrganizationUnit::query()->create(['organization_id' => $org->id, 'code' => 'E2E-U', 'name_en' => 'HR', 'unit_type' => 'directorate', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $org->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'E2E-P', 'title_en' => 'Officer', 'is_active' => true]);
    $alem = e2eEmployee('E-1', $org, $unit, $position, 'female');
    $bekele = e2eEmployee('E-2', $org, $unit, $position, 'male');
    $behavioural = AssessmentType::query()->where('code', 'BEHAVIORAL_COMPETENCY')->firstOrFail();

    $city = e2eUser([
        'assessment_forms.view', 'assessment_forms.create', 'assessment_forms.edit_draft', 'assessment_forms.publish', 'assessment_forms.archive',
        'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status', 'assessment_oversight.view_results',
        'assessment_oversight.view_demographics', 'assessment_oversight.view_data_quality', 'assessment_oversight.manage_cycles', 'assessment_oversight.manage_policies',
        'assessment_submissions.review', 'assessment_submissions.return', 'assessment_submissions.verify', 'assessment_submissions.finalize', 'assessment_reports.view', 'assessment_reports.export',
    ]);
    $reviewer = e2eUser(['assessment_forms.view', 'assessment_forms.publish', 'assessments.review', 'assessments.finalize', 'assessment_oversight.view_dashboard', 'assessment_submissions.review', 'assessment_submissions.verify']);
    $institution = e2eUser(['assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status', 'assessment_submissions.submit'], $org);
    $evaluator = ['assessments.view_assigned', 'assessments.complete_assigned', 'assessments.submit', 'assessments.view_own_result'];
    $alemUser = e2eUser($evaluator, null, $alem);
    $bekeleUser = e2eUser($evaluator, null, $bekele);

    // 1. Form Builder: a peer-only behavioural form, published.
    $this->actingAs($city)->post(route('assessment-forms.store'), ['code' => 'E2E-PEER', 'name_en' => 'Peer behaviour', 'assessment_type_id' => $behavioural->id])->assertSessionHasNoErrors();
    $form = AssessmentForm::query()->where('code', 'E2E-PEER')->firstOrFail();
    $draft = $form->draftVersion();
    $criterion = fn (string $title) => ['title_en' => $title, 'comment_mode' => 'optional', 'evidence_mode' => 'disabled', 'is_required' => true,
        'options' => [['description_en' => 'Always', 'score' => 3], ['description_en' => 'Usually', 'score' => 2], ['description_en' => 'Rarely', 'score' => 1]]];
    $this->actingAs($city)->put(route('assessment-forms.versions.save', $draft), [
        'name_en' => 'Peer behaviour', 'scoring_method' => 'percent_of_max', 'max_total_score' => 6, 'acknowledgement_required' => true, 'review_required' => true,
        'sections' => [['title_en' => 'Teamwork', 'max_score' => 6, 'is_required' => true, 'criteria' => [$criterion('Shares knowledge'), $criterion('Supports colleagues')]]],
        'target_rules' => [['target_type' => 'everyone', 'effect' => 'include']],
        'evaluators' => [['evaluator_type' => 'peer', 'required_count' => 1, 'selection_method' => 'admin_selected', 'is_anonymous' => true, 'requires_review' => true]],
    ])->assertSessionHasNoErrors();
    $this->actingAs($city)->post(route('assessment-forms.versions.publish', $draft))->assertSessionHasNoErrors();

    // 2. Oversight setup: result bands, a cycle, its institution, a finalized eligibility snapshot.
    $this->actingAs($city)->post(route('assessment-oversight.band-policies.store'), ['code' => 'E2E-BANDS', 'name_en' => 'Bands'])->assertSessionHasNoErrors();
    $policy = AssessmentResultBandPolicy::query()->where('code', 'E2E-BANDS')->firstOrFail();
    $this->actingAs($city)->put(route('assessment-oversight.band-policies.update', $policy), [
        'name_en' => 'Bands', 'range_min' => 0, 'range_max' => 100, 'requires_full_coverage' => true,
        'bands' => [
            ['code' => 'LOW', 'label_en' => 'Low', 'min_score' => 0, 'max_score' => 70, 'min_inclusive' => true, 'max_inclusive' => false],
            ['code' => 'HIGH', 'label_en' => 'High', 'min_score' => 70, 'max_score' => 100, 'min_inclusive' => true, 'max_inclusive' => true],
        ],
    ])->assertSessionHasNoErrors();
    $this->actingAs($city)->post(route('assessment-oversight.band-policies.activate', $policy))->assertSessionHasNoErrors();
    $this->actingAs($city)->post(route('assessment-oversight.cycles.store'), [
        'code' => 'E2E-2026', 'name_en' => '2026 first half', 'assessment_type_id' => $behavioural->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'reference_date' => '2026-06-30', 'submission_deadline' => '2026-07-31',
        'result_band_policy_id' => $policy->id, 'population_rule' => 'all_assigned',
    ])->assertSessionHasNoErrors();
    $cycle = AssessmentCycle::query()->where('code', 'E2E-2026')->firstOrFail();
    $this->actingAs($city)->post(route('assessment-oversight.cycles.organizations.store', $cycle), ['organization_ids' => [$org->id]])->assertSessionHasNoErrors();
    $this->actingAs($city)->post(route('assessment-oversight.cycles.status', $cycle), ['status' => 'active'])->assertSessionHasNoErrors();
    $this->actingAs($city)->post(route('assessment-oversight.cycles.eligibility.snapshot', $cycle))->assertSessionHasNoErrors();
    $this->actingAs($city)->post(route('assessment-oversight.cycles.eligibility.finalize', $cycle))->assertSessionHasNoErrors();

    // 3. Operations: assign Alem (rated by Bekele) and Bekele (rated by Alem) for the cycle's period.
    foreach ([[$alem, $bekeleUser], [$bekele, $alemUser]] as [$employee, $peer]) {
        $this->actingAs($city)->post(route('assessment-records.store'), [
            'employee_id' => $employee->id, 'form_id' => $form->id, 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
            'reviewer_id' => $reviewer->id, 'evaluator_ids' => [$peer->id],
        ])->assertSessionHasNoErrors();
    }
    $alemRecord = AssessmentRecord::query()->where('employee_id', $alem->id)->firstOrFail();
    $bekeleRecord = AssessmentRecord::query()->where('employee_id', $bekele->id)->firstOrFail();
    expect($alemRecord->assessment_cycle_id)->toBe($cycle->id);

    // The Assessments page: canvas counts, evaluator progress and the status filter.
    $this->actingAs($city)->get(route('assessment-records.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('summary.total', 2)->where('summary.awaiting_ratings', 2)->where('summary.completed', 0)
            ->where('records.data.0.evaluators', ['submitted' => 0, 'total' => 1]));
    $this->actingAs($city)->get(route('assessment-records.index', ['status' => 'reviewed']))
        ->assertInertia(fn (AssertableInertia $p) => $p->has('records.data', 0)->where('summary.total', 2));

    // Nothing is assessed yet: assignment alone never counts.
    $this->actingAs($city)->get(route('assessment-oversight.dashboard', ['cycle' => $cycle->id]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('data.totals.eligible', 2)->where('data.totals.assessed', 0)->where('data.totals.assigned', 2));

    // 4. Bekele rates Alem (best then middle option: 5 / 6 = 83.33 %); the reviewer signs it off; Alem acknowledges.
    $alemRecord->load('version.sections.criteria.options');
    $answers = [];
    foreach ($alemRecord->version->sections->flatMap->criteria->values() as $i => $c) {
        $answers[$c->id] = $c->options->sortByDesc('score')->values()[$i === 0 ? 0 : 1]->id;
    }
    $this->actingAs($bekeleUser)->post(route('assessment-records.submit', $alemRecord), ['answers' => $answers])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('assessment-records.review', $alemRecord))->assertSessionHasNoErrors();
    $this->actingAs($alemUser)->post(route('assessment-records.acknowledge', $alemRecord))->assertSessionHasNoErrors();
    expect($alemRecord->fresh()->status)->toBe('acknowledged')->and((string) $alemRecord->fresh()->percentage)->toBe('83.3333');

    // Bekele is on approved leave: the institution records the reason.
    $this->actingAs($city)->post(route('assessment-records.unassessed', $bekeleRecord), ['reason' => 'illness', 'note' => 'Medical leave'])->assertSessionHasNoErrors();

    // 5. Oversight sees exactly that: 1 of 2 assessed, the female employee, in the HIGH band, Bekele with a reason.
    $this->actingAs($city)->get(route('assessment-oversight.dashboard', ['cycle' => $cycle->id]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Assessments/Oversight/Dashboard')
            ->where('data.totals.assessed', 1)->where('data.totals.unassessed', 1)->where('data.totals.coverage_percent', '50.00')
            ->where('data.totals.gender.female.assessed', 1)->where('data.totals.outcomes.reported_unassessed', 1));
    $this->actingAs($city)->get(route('assessment-oversight.distribution', ['cycle' => $cycle->id]))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('data.bands.1.code', 'HIGH')->where('data.bands.1.count', 1));
    $this->actingAs($institution)->get(route('assessment-oversight.employees', ['cycle' => $cycle->id, 'outcome' => 'unassessed']))
        ->assertInertia(fn (AssertableInertia $p) => $p->has('rows.data', 1)->where('rows.data.0.employee_number', 'E-2')->where('rows.data.0.reason.code', 'illness'));

    // 6. Institution signs off, the city verifies (not the submitter) and finalizes.
    $this->actingAs($institution)->post(route('assessment-oversight.submissions.store', $cycle), ['organization_id' => $org->id, 'note' => 'Checked by HR'])->assertSessionHasNoErrors();
    $submission = AssessmentInstitutionSubmission::query()->firstOrFail();
    expect($submission->only(['eligible_count', 'assessed_count', 'unassessed_count', 'female_assessed']))->toBe(['eligible_count' => 2, 'assessed_count' => 1, 'unassessed_count' => 1, 'female_assessed' => 1]);
    $this->actingAs($reviewer)->post(route('assessment-oversight.submissions.move', [$submission, 'verify']))->assertSessionHasNoErrors();
    $this->actingAs($city)->post(route('assessment-oversight.submissions.move', [$submission, 'finalize']))->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe('finalized');

    $this->actingAs($city)->get(route('assessment-oversight.institutions', ['cycle' => $cycle->id]))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('rows.data.0.status', 'finalized')->where('rows.data.0.coverage_percent', '50.00'));
    Carbon::setTestNow();
});
