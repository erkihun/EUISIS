<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\Discipline\DisciplinaryCaseStatus;
use App\Enums\Discipline\DisciplinaryFinding;
use App\Enums\Discipline\DisciplinaryPenalty;
use App\Models\AuditLog;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseMember;
use App\Models\GrievanceCommitteeMember;
use App\Services\Discipline\DisciplinaryCaseAccess;
use App\Services\Discipline\DisciplinaryCaseService;
use App\Services\Legal\StatutoryCommitteeRules;
use App\Services\Legal\StatutoryDeadlineService;
use App\Support\Legal\Regulation201;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\DisciplineScenario;

beforeEach(function (): void {
    // Monday 5 October 2026: no public holidays in the following weeks.
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = DisciplineScenario::build();
    $this->cases = app(DisciplinaryCaseService::class);
});

afterEach(fn () => Carbon::setTestNow());

function chargedFormalCase(object $test, string $severity = 'serious'): DisciplinaryCase
{
    $case = $test->cases->open($test->s->officer, $test->s->accusedEmployee(), ['track' => 'formal', 'severity' => $severity, 'summary' => 'Unauthorised absence']);
    $test->cases->addCharge($case, $test->s->officer, [
        'title' => 'Absence without leave', 'particulars' => 'Absent 1–5 September without permission.',
        'occurred_on' => '2026-09-01', 'occurred_place' => 'Records Team', 'violated_provision' => 'Proclamation 87/2017 Art. 95',
        'evidence_list' => 'Attendance sheet; supervisor as witness',
    ]);
    $test->cases->assignPanel($case, $test->s->officer, $test->s->committee->id);
    $test->cases->fileCharge($case, $test->s->officer);

    return $case->refresh();
}

function seat(DisciplinaryCase $case, object $user): DisciplinaryCaseMember
{
    return $case->members()->where('employee_id', $user->employee_id)->firstOrFail();
}

function respondAndStatement(object $test, DisciplinaryCase $case): void
{
    $test->cases->recordResponse($case, $test->s->accused, [
        'pleas' => $case->charges()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['plea' => 'denied', 'statement' => 'I had sick leave.']])->all(),
        'acknowledged_name' => 'Accused Employee',
    ]);
    $test->cases->recordFinalStatement($case->refresh(), $test->s->accused, 'Please consider my record.', false);
}

it('calculates Regulation 201/2018 deadlines with the units the text states', function (): void {
    $deadlines = app(StatutoryDeadlineService::class);
    $monday = Carbon::parse('2026-10-05 09:00');

    // Art. 28(1)/(3): 5 consecutive working days — Monday to the next Monday.
    expect($deadlines->dueAt(Regulation201::DISCIPLINE_HEAD_DECISION, $monday)->toDateString())->toBe('2026-10-12')
        // Art. 34(3): 15 working days.
        ->and($deadlines->dueAt(Regulation201::GRIEVANCE_COMMITTEE_REPORT, $monday)->toDateString())->toBe('2026-10-26')
        // Art. 27(1): one month, a calendar month — not working days.
        ->and($deadlines->dueAt(Regulation201::DISCIPLINE_INVESTIGATION, $monday)->toDateString())->toBe('2026-11-05')
        // Art. 38(1)+(4): 30 days ending Saturday 31 Oct roll to Monday 2 Nov.
        ->and($deadlines->dueAt(Regulation201::APPEAL_FILING, Carbon::parse('2026-10-01'))->toDateString())->toBe('2026-11-02');

    // Art. 18(2): ten full working days (6–19 Oct) must pass before the hearing day.
    expect($deadlines->hasMinimumNotice(Regulation201::DISCIPLINE_SUMMONS_NOTICE, $monday, Carbon::parse('2026-10-20 09:00')))->toBeTrue()
        ->and($deadlines->hasMinimumNotice(Regulation201::DISCIPLINE_SUMMONS_NOTICE, $monday, Carbon::parse('2026-10-19 09:00')))->toBeFalse();
});

it('runs a formal case from charge to delivered decision without overwriting the record', function (): void {
    $case = chargedFormalCase($this);
    expect($case->status)->toBe(DisciplinaryCaseStatus::Charged)
        ->and($case->case_number)->toMatch('/^DSC-2026-\d{6}$/')
        ->and($case->members()->seated()->count())->toBe(5)
        ->and($case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_INVESTIGATION)->sole()->due_at->toDateString())->toBe('2026-11-05');

    // Art. 18(2): a hearing with only 9 clear working days is refused; 10 is accepted.
    expect(fn () => $this->cases->serveSummons($case, $this->s->officer, ['served_at' => '2026-10-05 09:00', 'method' => 'personal', 'hearing_at' => '2026-10-19 09:00', 'hearing_location' => 'Room 2']))
        ->toThrow(ValidationException::class);
    $this->cases->serveSummons($case, $this->s->officer, ['served_at' => '2026-10-05 09:00', 'method' => 'personal', 'hearing_at' => '2026-10-20 09:00', 'hearing_location' => 'Room 2']);

    respondAndStatement($this, $case);
    $case->refresh();
    expect($case->status)->toBe(DisciplinaryCaseStatus::UnderInvestigation)
        ->and($case->charges()->first()->plea->value)->toBe('denied');

    $votes = [
        seat($case, $this->s->chair)->id => ['vote' => 'for'],
        seat($case, $this->s->electedWoman)->id => ['vote' => 'for'],
        seat($case, $this->s->elected)->id => ['vote' => 'against', 'dissent_reason' => 'Sick leave was plausible.'],
    ];
    $recommendation = $this->cases->adoptRecommendation($case, $this->s->chair, [
        'finding' => 'responsible', 'penalty' => 'salary_fine_months', 'penalty_amount' => 1,
        'gravity_assessment' => 'Five days of absence.', 'conduct_assessment' => 'Satisfactory record.', 'prior_record_assessment' => 'No prior penalty.',
        'reasoning' => 'Absence established by attendance records.', 'votes' => $votes,
    ]);
    $case->refresh();
    expect($case->status)->toBe(DisciplinaryCaseStatus::ReportSubmitted)
        ->and($recommendation->votes_for)->toBe(2)
        ->and($recommendation->votes()->whereNotNull('dissent_reason')->count())->toBe(1)
        ->and($case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_INVESTIGATION)->sole()->completed_at)->not->toBeNull()
        ->and($case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_HEAD_DECISION)->sole()->due_at->toDateString())->toBe('2026-10-12');

    $decision = $this->cases->decide($case, $this->s->head, ['action' => 'modify', 'finding' => 'responsible', 'penalty' => 'written_warning', 'reason' => 'A warning is proportionate.']);
    $case->refresh();
    expect($case->status)->toBe(DisciplinaryCaseStatus::Decided)
        ->and($decision->penalty)->toBe(DisciplinaryPenalty::WrittenWarning)
        // The committee's recommendation is preserved as adopted, not rewritten.
        ->and($recommendation->refresh()->recommended_penalty)->toBe(DisciplinaryPenalty::SalaryFineMonths)
        ->and($case->delivered_at)->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-10-05 16:00:00'));
    $this->cases->recordDelivery($case, $this->s->officer, ['delivered_at' => '2026-10-05 15:00', 'method' => 'personal']);
    $this->cases->close($case->refresh(), $this->s->officer);

    expect($case->refresh()->status)->toBe(DisciplinaryCaseStatus::Closed)
        ->and($case->history()->pluck('to_status')->all())->toBe(['draft', 'charged', 'under_investigation', 'report_submitted', 'decided', 'decision_delivered', 'closed'])
        ->and(AuditLog::query()->where('event_type', AuditEventType::DisciplinaryDecisionDelivered->value)->exists())->toBeTrue();
});

it('applies the Art. 29 effective date only after actual delivery', function (): void {
    $case = chargedFormalCase($this);
    respondAndStatement($this, $case);
    $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, [
        'finding' => 'responsible', 'penalty' => 'salary_fine_months', 'penalty_amount' => 2,
        'gravity_assessment' => 'g', 'conduct_assessment' => 'c', 'prior_record_assessment' => 'p', 'reasoning' => 'r',
        'votes' => [seat($case, $this->s->chair)->id => ['vote' => 'for'], seat($case, $this->s->appointed)->id => ['vote' => 'for'], seat($case, $this->s->elected)->id => ['vote' => 'for']],
    ]);
    $decision = $this->cases->decide($case->refresh(), $this->s->head, ['action' => 'approve', 'reason' => 'Agreed.']);
    expect($decision->effective_on)->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00'));
    $this->cases->recordDelivery($case->refresh(), $this->s->officer, ['delivered_at' => '2026-10-06 10:00', 'method' => 'personal']);
    expect($decision->refresh()->effective_on->toDateString())->toBe('2026-11-05');
});

it('enforces Art. 8 quorum, the chair tie-break and written dissent', function (): void {
    $case = chargedFormalCase($this);
    respondAndStatement($this, $case);
    $base = ['finding' => 'not_established', 'reasoning' => 'Not proven.'];
    $chair = seat($case, $this->s->chair)->id;
    $a = seat($case, $this->s->appointed)->id;
    $b = seat($case, $this->s->elected)->id;
    $c = seat($case, $this->s->electedWoman)->id;

    // Chair plus one member is not a quorum.
    expect(fn () => $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, [...$base, 'votes' => [$chair => ['vote' => 'for'], $a => ['vote' => 'for']]]))
        ->toThrow(ValidationException::class, __('discipline.errors.quorum_not_met'));
    // Three members without the chair is not a quorum either.
    expect(fn () => $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, [...$base, 'votes' => [$a => ['vote' => 'for'], $b => ['vote' => 'for'], $c => ['vote' => 'for']]]))
        ->toThrow(ValidationException::class, __('discipline.errors.quorum_not_met'));
    // A dissent without a reason is refused.
    expect(fn () => $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, [...$base, 'votes' => [$chair => ['vote' => 'for'], $a => ['vote' => 'for'], $b => ['vote' => 'against']]]))
        ->toThrow(ValidationException::class, __('discipline.errors.dissent_reason_required'));

    // 2–2 with the chair in favour: the chair's side carries.
    $rec = $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, [...$base, 'votes' => [
        $chair => ['vote' => 'for'], $a => ['vote' => 'for'],
        $b => ['vote' => 'against', 'dissent_reason' => 'r1'], $c => ['vote' => 'against', 'dissent_reason' => 'r2'],
    ]]);
    expect($rec->tie_broken_by_chair)->toBeTrue()->and($rec->finding)->toBe(DisciplinaryFinding::NotEstablished);
});

it('keeps the accused, outsiders and non-seated staff out of institution actions', function (): void {
    $case = chargedFormalCase($this);
    $access = app(DisciplinaryCaseAccess::class);

    expect($access->canView($this->s->accused, $case))->toBeTrue()
        ->and($access->canView($this->s->outsider, $case))->toBeFalse()
        ->and($access->canView($this->s->bystander, $case))->toBeFalse()
        ->and($access->canInvestigate($this->s->bystander, $case))->toBeFalse()
        ->and($access->canOperate($this->s->accused, $case, 'discipline.decide'))->toBeFalse();

    expect(fn () => $this->cases->open($this->s->outsider, $this->s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->cases->recordWitness($case, $this->s->bystander, ['called_by' => 'institution', 'name' => 'W']))
        ->toThrow(AuthorizationException::class);
});

it('lets a recused member neither vote nor see the case, and seats a case-only replacement', function (): void {
    $case = chargedFormalCase($this);
    $this->cases->recuse($case, $this->s->officer, seat($case, $this->s->elected), 'Brother-in-law of the accused (Art. 9(1)).', $this->s->bystander->employee_id);

    $access = app(DisciplinaryCaseAccess::class);
    expect($access->canView($this->s->elected, $case))->toBeFalse()
        ->and($access->canView($this->s->bystander, $case))->toBeTrue()
        // Committee membership itself is untouched.
        ->and(GrievanceCommitteeMember::query()->where('employee_id', $this->s->elected->employee_id)->sole()->status)->toBe('active');

    respondAndStatement($this, $case);
    $recused = $case->members()->where('employee_id', $this->s->elected->employee_id)->sole()->id;
    expect(fn () => $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, [
        'finding' => 'not_established', 'reasoning' => 'r',
        'votes' => [seat($case, $this->s->chair)->id => ['vote' => 'for'], seat($case, $this->s->appointed)->id => ['vote' => 'for'], $recused => ['vote' => 'for']],
    ]))->toThrow(ValidationException::class, __('discipline.errors.vote_invalid'));
});

it('does not let a seated committee member act as the deciding head', function (): void {
    $case = chargedFormalCase($this);
    $this->s->chair->givePermissionTo('discipline.decide');
    respondAndStatement($this, $case);
    $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, [
        'finding' => 'not_established', 'reasoning' => 'r',
        'votes' => [seat($case, $this->s->chair)->id => ['vote' => 'for'], seat($case, $this->s->appointed)->id => ['vote' => 'for'], seat($case, $this->s->elected)->id => ['vote' => 'for']],
    ]);

    expect(fn () => $this->cases->decide($case->refresh(), $this->s->chair, ['action' => 'approve', 'reason' => 'x']))->toThrow(AuthorizationException::class);
});

it('refuses panels whose composition breaks Art. 5', function (): void {
    GrievanceCommitteeMember::query()->where('employee_id', $this->s->electedWoman->employee_id)->update(['selection_source' => 'appointed']);
    $rules = app(StatutoryCommitteeRules::class);
    expect($rules->compositionProblems($this->s->committee->refresh()))->toContain('appointed_count', 'elected_count');

    $case = $this->cases->open($this->s->officer, $this->s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious']);
    expect(fn () => $this->cases->assignPanel($case, $this->s->officer, $this->s->committee->id))
        ->toThrow(ValidationException::class, __('discipline.errors.committee_composition'));
});

it('allows only statutory penalties within their caps', function (): void {
    $case = chargedFormalCase($this);
    respondAndStatement($this, $case);
    $votes = [seat($case, $this->s->chair)->id => ['vote' => 'for'], seat($case, $this->s->appointed)->id => ['vote' => 'for'], seat($case, $this->s->elected)->id => ['vote' => 'for']];
    $factors = ['gravity_assessment' => 'g', 'conduct_assessment' => 'c', 'prior_record_assessment' => 'p', 'reasoning' => 'r', 'votes' => $votes];

    expect(fn () => $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, ['finding' => 'responsible', 'penalty' => 'salary_fine_days', 'penalty_amount' => 16, ...$factors]))
        ->toThrow(ValidationException::class, __('discipline.errors.penalty_amount_range'));
    expect(fn () => $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, ['finding' => 'not_established', 'penalty' => 'dismissal', ...$factors]))
        ->toThrow(ValidationException::class, __('discipline.errors.no_penalty_without_finding'));
    expect(fn () => $this->cases->adoptRecommendation($case->refresh(), $this->s->chair, ['finding' => 'responsible', 'penalty' => 'dismissal', 'reasoning' => 'r', 'votes' => $votes]))
        ->toThrow(ValidationException::class, __('discipline.errors.factor_required'));
});

it('treats silence as no admission and requires a plea on every charge', function (): void {
    $case = chargedFormalCase($this);
    expect(fn () => $this->cases->recordResponse($case, $this->s->accused, ['pleas' => [], 'acknowledged_name' => 'A']))
        ->toThrow(ValidationException::class, __('discipline.errors.plea_every_charge'));
    expect($case->charges()->first()->plea)->toBeNull();
});

it('keeps an expedited inquiry to minor penalties and without invented deadlines', function (): void {
    expect(fn () => $this->cases->open($this->s->officer, $this->s->accusedEmployee(), ['track' => 'expedited', 'severity' => 'serious']))
        ->toThrow(ValidationException::class);

    $case = $this->cases->open($this->s->officer, $this->s->accusedEmployee(), ['track' => 'expedited', 'severity' => 'minor']);
    $this->cases->addCharge($case, $this->s->officer, ['title' => 'Late', 'particulars' => 'p', 'occurred_on' => '2026-09-01', 'occurred_place' => 'o', 'violated_provision' => 'v', 'evidence_list' => 'e']);
    $this->cases->assignPanel($case, $this->s->officer, null, [$this->s->secretary->employee_id]);
    $this->cases->fileCharge($case, $this->s->officer);
    $this->cases->recordResponse($case->refresh(), $this->s->accused, ['pleas' => [$case->charges()->first()->id => ['plea' => 'admitted']], 'acknowledged_name' => 'A']);

    expect(fn () => $this->cases->adoptRecommendation($case->refresh(), $this->s->secretary, ['finding' => 'responsible', 'penalty' => 'dismissal', 'gravity_assessment' => 'g', 'conduct_assessment' => 'c', 'prior_record_assessment' => 'p', 'reasoning' => 'r']))
        ->toThrow(ValidationException::class, __('discipline.errors.serious_penalty_needs_committee'));

    $this->cases->adoptRecommendation($case->refresh(), $this->s->secretary, ['finding' => 'responsible', 'penalty' => 'oral_warning', 'gravity_assessment' => 'g', 'conduct_assessment' => 'c', 'prior_record_assessment' => 'p', 'reasoning' => 'Admitted.']);
    expect($case->refresh()->deadlines()->count())->toBe(0);
});

it('blocks re-filing a discontinued charge on the same matter (Art. 17(5))', function (): void {
    $source = ['track' => 'formal', 'severity' => 'serious', 'source_type' => DisciplinaryCase::SOURCE_PUBLIC_COMPLAINT, 'source_id' => (string) Str::uuid()];
    $case = $this->cases->open($this->s->officer, $this->s->accusedEmployee(), $source);
    $this->cases->discontinue($case, $this->s->officer, 'Withdrawn in writing.');

    expect($case->refresh()->status)->toBe(DisciplinaryCaseStatus::Discontinued)
        ->and(fn () => $this->cases->open($this->s->officer, $this->s->accusedEmployee(), $source))->toThrow(ValidationException::class);
});
