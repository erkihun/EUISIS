<?php

declare(strict_types=1);

use App\Enums\Assessment\FormVersionStatus;
use App\Models\AssessmentForm;
use App\Models\AssessmentFormVersion;
use App\Models\AssessmentType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Assessment\AssessmentScoringService;
use App\Services\Assessment\AssessmentTargetResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Assessment Form Builder — docs/assessment-form-builder.md
|--------------------------------------------------------------------------
| Forms are configuration: nothing here is the content of a specific paper
| form. The scoring example (24 / 30 → 80% → at 5% contributes 4.0) is the
| specification's illustration, built through the API like any form.
*/

const AF_ALL = ['assessment_forms.view', 'assessment_forms.create', 'assessment_forms.edit_draft', 'assessment_forms.publish', 'assessment_forms.archive'];

function afUser(array $permissions, ?Organization $scope = null): User
{
    $role = Role::findOrCreate('AF '.Str::random(6), 'web');
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);
    if ($scope !== null) {
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $scope->id, 'scope_type' => 'self', 'is_active' => true]);
        app(OrganizationScopeService::class)->clearCache();
    }

    return $user->fresh();
}

function afEmployee(string $number, Organization $org, OrganizationUnit $unit, Position $position, string $from = '2026-01-01', ?string $to = null): Employee
{
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => $number, 'last_name' => 'E', 'full_name' => "{$number} E", 'status' => 'active']);
    EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id,
        'assignment_status' => $to === null ? 'active' : 'closed', 'effective_from' => $from, 'effective_to' => $to, 'is_current' => $to === null,
    ]);

    return $employee;
}

/**
 * A complete, valid draft: two sections, decimal options, a manager and
 * a peer evaluator with weights, and the given target rules.
 *
 * @param  array<int, array<string, mixed>>  $rules
 * @return array<string, mixed>
 */
function afDraft(array $rules, array $overrides = []): array
{
    $criterion = fn (string $title) => [
        'title_en' => $title, 'comment_mode' => 'optional', 'evidence_mode' => 'disabled', 'is_required' => true,
        'options' => [
            ['description_en' => 'Always', 'score' => 3],
            ['description_en' => 'Usually', 'score' => 2],
            ['description_en' => 'Sometimes', 'score' => 1],
            ['description_en' => 'Rarely', 'score' => 0.5],
        ],
    ];

    return [
        'name_en' => 'Behavioural assessment', 'scoring_method' => 'percent_of_max',
        'max_total_score' => 12, 'overall_contribution_weight' => 5,
        'acknowledgement_required' => true, 'review_required' => false,
        'sections' => [
            ['title_en' => 'Teamwork', 'max_score' => 6, 'is_required' => true, 'criteria' => [$criterion('Shares knowledge'), $criterion('Supports colleagues')]],
            ['title_en' => 'Service', 'max_score' => 6, 'is_required' => true, 'criteria' => [$criterion('Courtesy'), $criterion('Timeliness')]],
        ],
        'target_rules' => $rules,
        'evaluators' => [
            ['evaluator_type' => 'direct_manager', 'required_count' => 1, 'contribution_weight' => 95, 'selection_method' => 'system', 'is_anonymous' => false, 'requires_review' => false],
            ['evaluator_type' => 'peer', 'required_count' => 2, 'contribution_weight' => 5, 'selection_method' => 'manager_selected', 'is_anonymous' => true, 'requires_review' => false],
        ],
        ...$overrides,
    ];
}

function afCreate(object $test, User $admin, string $code, ?Organization $org = null): AssessmentForm
{
    $test->actingAs($admin)->post(route('assessment-forms.store'), [
        'code' => $code, 'name_en' => "Form {$code}", 'assessment_type_id' => $test->type->id, 'organization_id' => $org?->id,
    ])->assertSessionHasNoErrors();

    return AssessmentForm::query()->where('code', $code)->firstOrFail();
}

function afPublished(object $test, User $admin, string $code, array $rules, array $overrides = []): AssessmentFormVersion
{
    $form = afCreate($test, $admin, $code);
    $draft = $form->draftVersion();
    $test->actingAs($admin)->put(route('assessment-forms.versions.save', $draft), afDraft($rules, $overrides))->assertSessionHasNoErrors();
    $test->actingAs($admin)->post(route('assessment-forms.versions.publish', $draft))->assertSessionHasNoErrors();

    return $draft->fresh();
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-08 10:00:00');
    $orgType = OrganizationType::query()->create(['code' => 'AF-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $orgType->id, 'code' => 'AF-ORG', 'name_en' => 'Civil Service Bureau', 'status' => 'active']);
    $this->otherOrg = Organization::query()->create(['organization_type_id' => $orgType->id, 'code' => 'AF-OTH', 'name_en' => 'Health Bureau', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'AF-HR', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->subUnit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'parent_unit_id' => $this->unit->id, 'code' => 'AF-HR-1', 'name_en' => 'Records', 'unit_type' => 'team', 'status' => 'active']);
    $this->director = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'AF-D', 'title_en' => 'Director', 'grade_level' => 'XV', 'job_family' => 'Management', 'is_active' => true]);
    $this->officer = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->subUnit->id, 'job_position_code' => 'AF-O', 'title_en' => 'HR Officer', 'grade_level' => 'IX', 'job_family' => 'Professional', 'is_active' => true]);
    $this->type = AssessmentType::query()->where('code', 'BEHAVIORAL_COMPETENCY')->firstOrFail();
    $this->admin = afUser(AF_ALL);
});

afterEach(fn () => Carbon::setTestNow());

test('published peer form completes assignment submission review and employee acknowledgement', function (): void {
    $employee = afEmployee('FLOW-EMP', $this->org, $this->unit, $this->officer);
    $peerEmployee = afEmployee('FLOW-PEER', $this->org, $this->unit, $this->officer);
    $subject = afUser(['assessments.view_own_result']);
    $subject->forceFill(['employee_id' => $employee->id])->save();
    $peer = afUser(['assessments.view_assigned', 'assessments.complete_assigned', 'assessments.submit']);
    $reviewer = afUser([...AF_ALL, 'assessments.review', 'assessments.finalize']);
    $peer->forceFill(['employee_id' => $peerEmployee->id])->save();
    $version = afPublished($this, $this->admin, 'FLOW', [['target_type' => 'everyone', 'effect' => 'include']], [
        'evaluators' => [['evaluator_type' => 'peer', 'required_count' => 1, 'contribution_weight' => 100, 'selection_method' => 'admin_selected', 'is_anonymous' => true, 'requires_review' => true]],
        'review_required' => true,
    ]);
    $this->actingAs($this->admin)->post(route('assessment-records.store'), [
        'employee_id' => $employee->id, 'form_id' => $version->form_id,
        'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
        'reviewer_id' => $reviewer->id, 'evaluator_ids' => [$peer->id],
    ])->assertSessionHasNoErrors();
    $record = \App\Models\AssessmentRecord::query()->where('employee_id', $employee->id)->firstOrFail();
    $this->actingAs($peer)->get(route('assessment-records.show', $record))->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('version.name_en', $version->name_en)->where('version.version_no', 1)->where('can.submit', true));
    $this->actingAs($subject)->post(route('assessment-records.submit', $record), ['answers' => [(string) \Illuminate\Support\Str::uuid() => (string) \Illuminate\Support\Str::uuid()]])->assertForbidden();
    $answers = $version->load('sections.criteria.options')->sections->flatMap->criteria
        ->mapWithKeys(fn ($criterion) => [$criterion->id => $criterion->options->sortByDesc('score')->first()->id])->all();
    $this->actingAs($peer)->post(route('assessment-records.submit', $record), ['answers' => $answers])->assertSessionHasNoErrors();
    expect($record->fresh()->status)->toBe('submitted')->and($record->fresh()->percentage)->toBe('100.0000');
    // A submitted evaluation is locked: a second submission is refused.
    $this->actingAs($peer)->post(route('assessment-records.submit', $record), ['answers' => $answers])->assertForbidden();
    $this->actingAs($reviewer)->post(route('assessment-records.review', $record))->assertSessionHasNoErrors();
    expect($record->fresh()->status)->toBe('reviewed');
    $this->actingAs($subject)->post(route('assessment-records.acknowledge', $record))->assertSessionHasNoErrors();
    expect($record->fresh()->status)->toBe('acknowledged');
});

// ── Form builder ────────────────────────────────────────────────────────────

test('1-4. an administrator creates a draft form with sections, criteria and decimal rating options; others cannot', function (): void {
    $form = afCreate($this, $this->admin, 'AF-PRO');
    $draft = $form->draftVersion();
    expect($draft->version_no)->toBe(1)->and($draft->status)->toBe(FormVersionStatus::Draft);

    $this->actingAs($this->admin)->put(route('assessment-forms.versions.save', $draft), afDraft([['target_type' => 'everyone', 'effect' => 'include']]))->assertSessionHasNoErrors();
    $draft->load('sections.criteria.options');
    expect($draft->sections)->toHaveCount(2)
        ->and($draft->sections[0]->criteria)->toHaveCount(2)
        ->and((string) $draft->sections[0]->criteria[0]->options[3]->score)->toBe('0.5000');

    $this->actingAs(afUser(['assessment_forms.view']))->post(route('assessment-forms.store'), ['code' => 'X', 'name_en' => 'X', 'assessment_type_id' => $this->type->id])->assertForbidden();
    // A scoped administrator cannot create a city-wide form, only one of their organization.
    $scoped = afUser(AF_ALL, $this->org);
    $this->actingAs($scoped)->post(route('assessment-forms.store'), ['code' => 'AF-CITY', 'name_en' => 'X', 'assessment_type_id' => $this->type->id])->assertForbidden();
    afCreate($this, $scoped, 'AF-OWN', $this->org);
    expect(AuditLog::query()->where('event_type', 'assessment_forms.created')->count())->toBe(2);
});

test('6-7. an inconsistent form cannot be published; a valid one can', function (): void {
    $form = afCreate($this, $this->admin, 'AF-VAL');
    $draft = $form->draftVersion();

    // Nothing in it yet.
    $this->actingAs($this->admin)->post(route('assessment-forms.versions.publish', $draft))->assertSessionHasErrors(['sections', 'evaluators', 'target_rules']);

    // Configured maxima that do not add up, and evaluator weights that do not reach 100.
    $bad = afDraft([['target_type' => 'everyone', 'effect' => 'include']], ['max_total_score' => 30]);
    $bad['sections'][0]['max_score'] = 5;
    $bad['sections'][1]['criteria'][0]['max_score'] = 4;
    $bad['evaluators'][1]['contribution_weight'] = 10;
    $this->actingAs($this->admin)->put(route('assessment-forms.versions.save', $draft), $bad)->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('assessment-forms.versions.publish', $draft))
        ->assertSessionHasErrors(['max_total_score', 'sections.0.max_score', 'sections.1.criteria.0.max_score', 'evaluators']);
    expect($draft->fresh()->status)->toBe(FormVersionStatus::Draft);

    $this->actingAs($this->admin)->put(route('assessment-forms.versions.save', $draft), afDraft([['target_type' => 'everyone', 'effect' => 'include']]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('assessment-forms.versions.validate', $draft))->assertSessionHas('assessment_problems', []);
    $this->actingAs($this->admin)->post(route('assessment-forms.versions.publish', $draft))->assertSessionHasNoErrors();
    expect($draft->fresh()->status)->toBe(FormVersionStatus::Published)
        ->and($form->fresh()->current_version_id)->toBe($draft->id);
});

test('8-10. a published version is immutable; a new version is an independent draft and v1 is kept', function (): void {
    $v1 = afPublished($this, $this->admin, 'AF-VER', [['target_type' => 'everyone', 'effect' => 'include']]);
    $form = $v1->form;

    $this->actingAs($this->admin)->put(route('assessment-forms.versions.save', $v1), afDraft([['target_type' => 'everyone', 'effect' => 'include']], ['name_en' => 'Changed']))
        ->assertSessionHasErrors('version');

    $this->actingAs($this->admin)->post(route('assessment-forms.versions.store', $form))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('assessment-forms.versions.store', $form))->assertSessionHasErrors('version');
    $v2 = $form->draftVersion();
    expect($v2->version_no)->toBe(2)->and($v2->sections()->count())->toBe(2);

    // Change a rating score in v2 only.
    $changed = afDraft([['target_type' => 'everyone', 'effect' => 'include']]);
    $changed['sections'][0]['criteria'][0]['options'][0]['score'] = 4;
    $changed['sections'][0]['max_score'] = 7;
    $changed['max_total_score'] = 13;
    $this->actingAs($this->admin)->put(route('assessment-forms.versions.save', $v2), $changed)->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('assessment-forms.versions.publish', $v2))->assertSessionHasNoErrors();

    $v1->refresh()->load('sections.criteria.options');
    expect($v1->status)->toBe(FormVersionStatus::Superseded)
        ->and((string) $v1->sections[0]->criteria[0]->options[0]->score)->toBe('3.0000')
        ->and($form->fresh()->current_version_id)->toBe($v2->id);
});

test('23. a clone is an independent draft of the same content', function (): void {
    $v1 = afPublished($this, $this->admin, 'AF-SRC', [['target_type' => 'everyone', 'effect' => 'include']]);
    $this->actingAs($this->admin)->post(route('assessment-forms.clone', $v1->form), ['code' => 'AF-DIR', 'name_en' => 'Director assessment'])->assertSessionHasNoErrors();

    $clone = AssessmentForm::query()->where('code', 'AF-DIR')->firstOrFail();
    $draft = $clone->draftVersion()->load('sections.criteria.options', 'evaluatorSchemes');
    expect($draft->version_no)->toBe(1)
        ->and($draft->name_en)->toBe('Director assessment')
        ->and($draft->sections)->toHaveCount(2)
        ->and($draft->evaluatorSchemes)->toHaveCount(2)
        ->and($draft->sections[0]->id)->not->toBe($v1->sections()->first()->id);
});

// ── Scoring ─────────────────────────────────────────────────────────────────

test('29-35. scoring uses the selected scores, converts to a contribution and refuses tampering', function (): void {
    $scoring = app(AssessmentScoringService::class);
    $version = afPublished($this, $this->admin, 'AF-SCORE', [['target_type' => 'everyone', 'effect' => 'include']])
        ->load('sections.criteria.options');
    $criteria = $version->sections->flatMap->criteria->values();

    // 3 + 2 + 1 + 0.5 = 6.5 of 12.
    $result = $scoring->score($version, [$criteria[0]->id => '3', $criteria[1]->id => '2', $criteria[2]->id => '1', $criteria[3]->id => '0.5']);
    expect($scoring->round($result['raw']))->toBe('6.5000')
        ->and($scoring->round($result['max']))->toBe('12.0000')
        ->and($scoring->round($result['percentage']))->toBe('54.1667')
        // 54.1667% × 5 contribution weight / 100
        ->and($scoring->round($result['contribution']))->toBe('2.7083');

    // The specification's illustration: 24 of 30 is 80%, which at 5% contributes 4.0.
    expect($scoring->round(bcdiv(bcmul(bcdiv(bcmul('24', '100', 10), '30', 10), '5', 10), '100', 10)))->toBe('4.0000');

    // A score above the criterion maximum is refused, whatever the browser sends.
    expect(fn () => $scoring->score($version, [$criteria[0]->id => '9']))->toThrow(InvalidArgumentException::class);

    // Manager 80% at 95, peers 60% at 5: 76 + 3 = 79. Never a plain average.
    expect($scoring->round($scoring->combine(['direct_manager' => ['percentage' => '80', 'weight' => '95'], 'peer' => ['percentage' => '60', 'weight' => '5']])))->toBe('79.0000')
        ->and($scoring->round($scoring->average(['60', '70'])))->toBe('65.0000');
});

test('weighted scoring weighs sections and must have weights adding up to 100', function (): void {
    $form = afCreate($this, $this->admin, 'AF-W');
    $draft = $form->draftVersion();
    $payload = afDraft([['target_type' => 'everyone', 'effect' => 'include']], ['scoring_method' => 'weighted_score']);
    $payload['sections'][0]['weight'] = 70;
    $this->actingAs($this->admin)->put(route('assessment-forms.versions.save', $draft), $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('assessment-forms.versions.publish', $draft))->assertSessionHasErrors('sections_weights');

    $payload['sections'][1]['weight'] = 30;
    $this->actingAs($this->admin)->put(route('assessment-forms.versions.save', $draft), $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('assessment-forms.versions.publish', $draft))->assertSessionHasNoErrors();

    $version = $draft->fresh()->load('sections.criteria.options');
    $criteria = $version->sections->flatMap->criteria->values();
    // Teamwork 6/6 = 100% × 70 + Service 3/6 = 50% × 30 = 85.
    $result = app(AssessmentScoringService::class)->score($version, [$criteria[0]->id => 3, $criteria[1]->id => 3, $criteria[2]->id => 2, $criteria[3]->id => 1]);
    expect(app(AssessmentScoringService::class)->round($result['final']))->toBe('85.0000');
});

// ── Target groups ───────────────────────────────────────────────────────────

test('11-16. the applicable form is resolved from the assignment on the date, with conflicts and gaps reported', function (): void {
    $resolver = app(AssessmentTargetResolver::class);
    $director = afEmployee('AF-1', $this->org, $this->unit, $this->director);
    $officer = afEmployee('AF-2', $this->org, $this->subUnit, $this->officer);
    $outsiderUnit = OrganizationUnit::query()->create(['organization_id' => $this->otherOrg->id, 'code' => 'AF-X', 'name_en' => 'Clinic', 'unit_type' => 'team', 'status' => 'active']);
    $outsiderPosition = Position::query()->create(['organization_id' => $this->otherOrg->id, 'organization_unit_id' => $outsiderUnit->id, 'job_position_code' => 'AF-X1', 'title_en' => 'Nurse', 'grade_level' => 'VII', 'is_active' => true]);
    $outsider = afEmployee('AF-3', $this->otherOrg, $outsiderUnit, $outsiderPosition);

    $leaders = afPublished($this, $this->admin, 'AF-LEAD', [['target_type' => 'position', 'target_id' => $this->director->id, 'effect' => 'include', 'priority' => 10]]);
    $professionals = afPublished($this, $this->admin, 'AF-PROF', [['target_type' => 'grade_level', 'target_value' => 'ix', 'effect' => 'include', 'priority' => 10]]);
    $today = Carbon::parse('2026-10-08');

    expect($resolver->resolveFor($director, $today, $this->type->id)['version']->id)->toBe($leaders->id)
        ->and($resolver->resolveFor($officer, $today, $this->type->id)['version']->id)->toBe($professionals->id)
        ->and($resolver->resolveFor($outsider, $today, $this->type->id)['status'])->toBe(AssessmentTargetResolver::NO_APPLICABLE_FORM);

    // A unit rule with sub-units at the same priority conflicts with the grade rule for the officer.
    $unitForm = afPublished($this, $this->admin, 'AF-UNIT', [['target_type' => 'organization_unit', 'target_id' => $this->unit->id, 'include_descendants' => true, 'effect' => 'include', 'priority' => 10],
        ['target_type' => 'position', 'target_id' => $this->director->id, 'effect' => 'exclude']]);
    $decision = $resolver->resolveFor($officer, $today, $this->type->id);
    expect($decision['status'])->toBe(AssessmentTargetResolver::CONFLICT)
        ->and($decision['candidates'])->toContain($unitForm->id, $professionals->id)
        // The exclude rule keeps the director on the leadership form only.
        ->and($resolver->resolveFor($director, $today, $this->type->id)['version']->id)->toBe($leaders->id);

    $preview = $resolver->preview($this->type->id, $today);
    expect($preview['total'])->toBe(3)->and($preview['conflicts'])->toBe(1)->and($preview['unmatched'])->toBe(1)
        ->and($preview['by_version'][$leaders->id])->toBe(1);

    // Scoped preview: only the viewer's organization.
    expect($resolver->preview($this->type->id, $today, [$this->otherOrg->id])['total'])->toBe(1);
});

test('16. resolution uses the assignment valid on the reference date, not the current one', function (): void {
    $employee = afEmployee('AF-MOVE', $this->org, $this->subUnit, $this->officer, '2026-01-01', '2026-06-30');
    EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->director->id, 'assignment_status' => 'active', 'effective_from' => '2026-07-01', 'is_current' => true]);
    $leaders = afPublished($this, $this->admin, 'AF-L2', [['target_type' => 'position', 'target_id' => $this->director->id, 'effect' => 'include']]);
    $professionals = afPublished($this, $this->admin, 'AF-P2', [['target_type' => 'position', 'target_id' => $this->officer->id, 'effect' => 'include']]);
    $resolver = app(AssessmentTargetResolver::class);

    expect($resolver->resolveFor($employee, Carbon::parse('2026-03-31'), $this->type->id)['version']->id)->toBe($professionals->id)
        ->and($resolver->resolveFor($employee, Carbon::parse('2026-09-30'), $this->type->id)['version']->id)->toBe($leaders->id);
});

// ── Scope and access ────────────────────────────────────────────────────────

test('38. an organization form is invisible and unmanageable outside its organization; targets stay inside it', function (): void {
    $owner = afUser(AF_ALL, $this->org);
    $form = afCreate($this, $owner, 'AF-PRIV', $this->org);
    $outsider = afUser(AF_ALL, $this->otherOrg);
    $draft = $form->draftVersion();

    $this->actingAs($outsider)->get(route('assessment-forms.show', $form))->assertForbidden();
    $this->actingAs($outsider)->put(route('assessment-forms.versions.save', $draft), afDraft([['target_type' => 'everyone', 'effect' => 'include']]))->assertForbidden();
    $this->actingAs($outsider)->post(route('assessment-forms.versions.publish', $draft))->assertForbidden();
    $this->actingAs($outsider)->get(route('assessment-forms.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('forms.data', fn ($rows) => collect($rows)->pluck('code')->doesntContain('AF-PRIV')));

    // An organization's form cannot target another organization's positions.
    $foreignUnit = OrganizationUnit::query()->create(['organization_id' => $this->otherOrg->id, 'code' => 'AF-F', 'name_en' => 'F', 'unit_type' => 'team', 'status' => 'active']);
    $this->actingAs($owner)->put(route('assessment-forms.versions.save', $draft), afDraft([['target_type' => 'organization_unit', 'target_id' => $foreignUnit->id, 'effect' => 'include']]))
        ->assertSessionHasErrors('target_rules.0.target_id');

    // Lookups are scoped too.
    $this->actingAs($owner)->getJson(route('assessment-forms.lookup', ['type' => 'organization_unit', 'q' => 'F']))->assertOk()
        ->assertJsonMissing(['id' => $foreignUnit->id]);
});

test('the list, builder and preview pages render, and the assignment preview counts employees', function (): void {
    afEmployee('AF-P1', $this->org, $this->subUnit, $this->officer);
    $version = afPublished($this, $this->admin, 'AF-PAGES', [['target_type' => 'grade_level', 'target_value' => 'IX', 'effect' => 'include']]);
    $form = $version->form;

    $this->actingAs($this->admin)->get(route('assessment-forms.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Assessments/Forms/Index')->where('forms.data.0.code', 'AF-PAGES')->where('forms.data.0.current_version', 1));
    $this->actingAs($this->admin)->get(route('assessment-forms.show', $form))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Assessments/Forms/Show')
            ->where('version.status', 'published')->where('can.edit', false)->where('can.newVersion', true)
            ->where('version.computed_max', fn ($max) => (float) $max === 12.0)
            ->has('version.sections', 2));
    $this->actingAs($this->admin)->get(route('assessment-forms.versions.preview', $version))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Assessments/Forms/Preview')->has('version.sections.0.criteria.0.options', 4));

    $this->actingAs($this->admin)->getJson(route('assessment-forms.assignment-preview', ['form' => $form->id, 'date' => '2026-10-08']))->assertOk()
        ->assertJson(['total' => 1, 'unmatched' => 0, 'conflicts' => 0, 'forms' => [['code' => 'AF-PAGES', 'employees' => 1]]]);
});

test('organization ownership bounds broad target rules and scoped previews', function (string $targetType, ?string $targetValue): void {
    $inside = afEmployee('AF-INSIDE', $this->org, $this->subUnit, $this->officer);
    $unit = OrganizationUnit::query()->create(['organization_id' => $this->otherOrg->id, 'code' => 'AF-OUT', 'name_en' => 'Other team', 'unit_type' => 'team', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $this->otherOrg->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'AF-OUT-P', 'title_en' => 'Officer', 'grade_level' => 'IX', 'job_family' => 'Professional', 'is_active' => true]);
    $outside = afEmployee('AF-OUTSIDE', $this->otherOrg, $unit, $position);
    $form = afCreate($this, $this->admin, 'AF-BOUNDED', $this->org);
    $draft = $form->draftVersion();
    $this->put(route('assessment-forms.versions.save', $draft), afDraft([['target_type' => $targetType, 'target_value' => $targetValue, 'effect' => 'include']]))->assertSessionHasNoErrors();
    $this->post(route('assessment-forms.versions.publish', $draft))->assertSessionHasNoErrors();

    $resolver = app(AssessmentTargetResolver::class);
    expect($resolver->resolveFor($inside, now(), $this->type->id)['status'])->toBe(AssessmentTargetResolver::MATCHED)
        ->and($resolver->resolveFor($outside, now(), $this->type->id)['status'])->toBe(AssessmentTargetResolver::NO_APPLICABLE_FORM);

    $city = afPublished($this, $this->admin, 'AF-PUBLIC', [['target_type' => 'everyone', 'effect' => 'include']]);
    $viewer = afUser(AF_ALL, $this->otherOrg);
    $this->actingAs($viewer)->getJson(route('assessment-forms.assignment-preview', ['form' => $city->form_id, 'date' => '2026-10-08']))
        ->assertOk()->assertJsonMissing(['code' => 'AF-BOUNDED'])->assertJson(['total' => 1, 'conflicts' => 0]);
})->with([['everyone', null], ['grade_level', 'IX'], ['job_family', 'Professional']]);

test('publishing updates form identity while a draft keeps the published identity intact', function (): void {
    $version = afPublished($this, $this->admin, 'AF-NAME', [['target_type' => 'everyone', 'effect' => 'include']]);
    expect($version->form->name_en)->toBe('Behavioural assessment');
    $this->post(route('assessment-forms.versions.store', $version->form))->assertSessionHasNoErrors();
    $draft = $version->form->draftVersion();
    $this->put(route('assessment-forms.versions.save', $draft), afDraft([['target_type' => 'everyone', 'effect' => 'include']], ['name_en' => 'Updated assessment']))->assertSessionHasNoErrors();
    expect($version->form->fresh()->name_en)->toBe('Behavioural assessment');
    $this->post(route('assessment-forms.versions.publish', $draft))->assertSessionHasNoErrors();
    expect($version->form->fresh()->name_en)->toBe('Updated assessment')
        ->and($version->fresh()->name_en)->toBe('Behavioural assessment');
    $this->get(route('assessment-forms.index'))->assertInertia(fn ($page) => $page->where('forms.data.0.name_en', 'Updated assessment'));
});

test('organization lookup respects the form owner', function (): void {
    $this->actingAs($this->admin)->getJson(route('assessment-forms.lookup', ['type' => 'organization', 'organization_id' => $this->org->id]))
        ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $this->org->id);
});

test('the sections editor round-trips rating labels and section descriptions; client-only keys are ignored', function (): void {
    $form = afCreate($this, $this->admin, 'AF-LBL');
    $draft = $form->draftVersion();
    $payload = afDraft([['target_type' => 'everyone', 'effect' => 'include']]);
    $payload['sections'][0]['description_en'] = 'How the employee works with others';
    $payload['sections'][0]['uid'] = 'client-key';
    $payload['sections'][0]['criteria'][0]['options'][0]['label_en'] = 'Always';
    $payload['sections'][0]['criteria'][0]['options'][0]['label_am'] = 'ሁልጊዜ';

    $this->actingAs($this->admin)->put(route('assessment-forms.versions.save', $draft), $payload)->assertSessionHasNoErrors();

    $section = $draft->fresh()->sections()->orderBy('sort_order')->firstOrFail();
    $option = $section->criteria()->orderBy('sort_order')->firstOrFail()->options()->orderBy('sort_order')->firstOrFail();
    expect($section->description_en)->toBe('How the employee works with others')
        ->and($option->label_en)->toBe('Always')->and($option->label_am)->toBe('ሁልጊዜ');
});

test('the sample form seeder publishes a valid peer form once', function (): void {
    $this->seed(\Database\Seeders\AssessmentSampleFormSeeder::class);
    $this->seed(\Database\Seeders\AssessmentSampleFormSeeder::class);

    $forms = AssessmentForm::query()->where('code', \Database\Seeders\AssessmentSampleFormSeeder::CODE)->get();
    expect($forms)->toHaveCount(1);
    $version = $forms->first()->versions()->firstOrFail();
    expect($version->status)->toBe(FormVersionStatus::Published)
        ->and($version->sections()->count())->toBe(3)
        ->and((string) $version->max_total_score)->toBe('28.0000')
        ->and($version->evaluatorSchemes()->first()->evaluator_type->value)->toBe('peer');
});
