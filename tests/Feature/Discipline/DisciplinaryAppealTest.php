<?php

declare(strict_types=1);

use App\Models\StatutoryDeadline;
use App\Support\Legal\Regulation201;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Discipline\Application\Services\DisciplinaryCasePresenter;
use Modules\Discipline\Application\Services\DisciplinaryCaseService;
use Modules\Discipline\Contracts\Data\DisciplinaryCaseStatus;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Tests\Support\DisciplineScenario;

/*
 * Regulation 201/2018 Part Five as the institution must act on it: the appeal
 * to the Administrative Court against a delivered disciplinary decision. The
 * penalty is not executed while the appeal is pending (Art. 29), a requested
 * record copy is due in 5 working days (Art. 37), the appeal window is 30 days
 * from delivery (Art. 38) with an absolute three-month bar for dismissal and
 * demotion (Art. 39(2)), a remand returns the case to the head (Art. 53) and a
 * ruling for the employee is carried out within 10 days (Art. 54).
 */

beforeEach(function (): void {
    // Monday 5 October 2026: no public holidays in the following weeks.
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = DisciplineScenario::build();
    $this->cases = app(DisciplinaryCaseService::class);
});

afterEach(fn () => Carbon::setTestNow());

/** A formal case decided with $penalty and delivered in person on Tuesday 6 October 2026. */
function dapDelivered(object $test, string $penalty, ?int $amount): DisciplinaryCase
{
    $s = $test->s;
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $case = $test->cases->open($s->officer, $s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious', 'summary' => 'Unauthorised absence']);
    $test->cases->addCharge($case, $s->officer, [
        'title' => 'Absence without leave', 'particulars' => 'Absent 1–5 September without permission.',
        'occurred_on' => '2026-09-01', 'occurred_place' => 'Records Team', 'violated_provision' => 'Proclamation 87/2017 Art. 95',
        'evidence_list' => 'Attendance sheet',
    ]);
    $test->cases->assignPanel($case, $s->officer, $s->committee->id);
    $test->cases->fileCharge($case, $s->officer);
    $case->refresh();
    $test->cases->recordResponse($case, $s->accused, [
        'pleas' => $case->charges()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['plea' => 'denied', 'statement' => 'I had sick leave.']])->all(),
        'acknowledged_name' => 'Accused Employee',
    ]);
    $test->cases->recordFinalStatement($case->refresh(), $s->accused, 'Please consider my record.', false);
    $seat = fn ($user) => $case->members()->where('employee_id', $user->employee_id)->firstOrFail()->id;
    $test->cases->adoptRecommendation($case->refresh(), $s->chair, [
        'finding' => 'responsible', 'penalty' => $penalty, 'penalty_amount' => $amount,
        'gravity_assessment' => 'g', 'conduct_assessment' => 'c', 'prior_record_assessment' => 'p', 'reasoning' => 'r',
        'votes' => [$seat($s->chair) => ['vote' => 'for'], $seat($s->appointed) => ['vote' => 'for'], $seat($s->elected) => ['vote' => 'for']],
    ]);
    $test->cases->decide($case->refresh(), $s->head, ['action' => 'approve', 'reason' => 'Agreed.']);

    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00'));
    $test->cases->recordDelivery($case->refresh(), $s->officer, ['delivered_at' => '2026-10-06 10:00', 'method' => 'personal']);

    return $case->refresh();
}

function dapDeadline(DisciplinaryCase $case, string $rule): ?StatutoryDeadline
{
    return $case->deadlines()->where('rule_key', $rule)->whereNull('superseded_at')->latest('started_at')->first();
}

it('opens the 30-day appeal window at delivery, and the three-month bar for dismissal or demotion', function (): void {
    $fine = dapDelivered($this, 'salary_fine_months', 2);

    // 6 October + 30 calendar days = 5 November 2026 (a Thursday, a working day).
    expect(dapDeadline($fine, Regulation201::APPEAL_FILING)->due_at->toDateString())->toBe('2026-11-05')
        ->and(dapDeadline($fine, Regulation201::APPEAL_LATE_ABSOLUTE))->toBeNull();

    $dismissal = dapDelivered($this, 'dismissal', null);
    expect(dapDeadline($dismissal, Regulation201::APPEAL_LATE_ABSOLUTE))->not->toBeNull();
});

it('stays the penalty while the appeal is pending and refuses to close the case', function (): void {
    $case = dapDelivered($this, 'salary_fine_months', 2);
    $decision = $case->currentDecision();
    expect($decision->effective_on->toDateString())->toBe('2026-11-05');

    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    $this->cases->recordAppealNotice($case, $this->s->officer, ['notice_received_at' => '2026-10-08 08:30', 'record_copy_requested' => true]);

    // Art. 37(2): the certified copy within 5 consecutive working days of the notice (Thursday 8 → Thursday 15 October).
    expect(dapDeadline($case, Regulation201::APPEAL_RECORD_COPY)->due_at->toDateString())->toBe('2026-10-15');

    $page = app(DisciplinaryCasePresenter::class)->detail($case->refresh(), $this->s->officer, 'en');
    expect($page['decisions'][0]['execution_stayed'])->toBeTrue()
        ->and($page['decisions'][0]['appeal']['record_copy_requested'])->toBeTrue();

    expect(fn () => $this->cases->close($case->refresh(), $this->s->officer))->toThrow(ValidationException::class);

    $this->cases->recordAppealRecordCopy($case->refresh(), $this->s->officer, '2026-10-08 08:45');
    expect(dapDeadline($case, Regulation201::APPEAL_RECORD_COPY)->completed_at)->not->toBeNull();
});

it('lets an upheld decision take effect only from the court decision, then closes', function (): void {
    $case = dapDelivered($this, 'salary_fine_months', 2);
    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    $this->cases->recordAppealNotice($case, $this->s->officer, ['notice_received_at' => '2026-10-08 08:30', 'record_copy_requested' => false]);
    $this->cases->recordAppealFiling($case->refresh(), $this->s->officer, '2026-10-08', 'AT/123/2019');

    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00'));
    $this->cases->recordAppealOutcome($case->refresh(), $this->s->officer, [
        'outcome' => 'upheld', 'decided_on' => '2026-11-20', 'received_at' => '2026-11-23 10:00', 'summary' => 'The appeal is dismissed.',
    ]);

    // Stayed past the original 5 November: effective from the court decision.
    expect($case->currentDecision()->effective_on->toDateString())->toBe('2026-11-20');
    $this->cases->close($case->refresh(), $this->s->officer);
    expect($case->refresh()->status)->toBe(DisciplinaryCaseStatus::Closed);
});

it('requires a ruling for the employee to be carried out before the case closes (Art. 54)', function (): void {
    $case = dapDelivered($this, 'demotion', 12);
    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    $this->cases->recordAppealNotice($case, $this->s->officer, ['notice_received_at' => '2026-10-08 08:30', 'record_copy_requested' => false]);
    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00'));
    $this->cases->recordAppealOutcome($case->refresh(), $this->s->officer, [
        'outcome' => 'reversed', 'decided_on' => '2026-11-20', 'received_at' => '2026-11-23 10:00', 'summary' => 'The demotion is set aside.',
    ]);

    // Art. 54(1): 10 days from receipt.
    expect(dapDeadline($case, Regulation201::APPEAL_EXECUTION)->due_at->toDateString())->toBe('2026-12-03');
    expect(fn () => $this->cases->close($case->refresh(), $this->s->officer))->toThrow(ValidationException::class);

    $this->cases->recordAppealImplemented($case->refresh(), $this->s->officer, '2026-11-30 09:00', 'Grade and salary restored.');
    expect(dapDeadline($case, Regulation201::APPEAL_EXECUTION)->completed_at)->not->toBeNull();
    $this->cases->close($case->refresh(), $this->s->officer);
    expect($case->refresh()->status)->toBe(DisciplinaryCaseStatus::Closed);
});

it('returns a remanded case to the head, who must decide again within 15 working days (Art. 53)', function (): void {
    $case = dapDelivered($this, 'salary_fine_months', 1);
    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    $this->cases->recordAppealNotice($case, $this->s->officer, ['notice_received_at' => '2026-10-08 08:30', 'record_copy_requested' => false]);
    Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00'));
    $this->cases->recordAppealOutcome($case->refresh(), $this->s->officer, [
        'outcome' => 'remanded', 'decided_on' => '2026-10-30', 'received_at' => '2026-11-02 08:00', 'summary' => 'The witness was not heard.',
    ]);

    $case->refresh();
    expect($case->status)->toBe(DisciplinaryCaseStatus::ReportSubmitted)
        ->and(dapDeadline($case, Regulation201::APPEAL_REMAND_RECONSIDERATION)->due_at->toDateString())->toBe('2026-11-23');

    $this->cases->decide($case, $this->s->head, ['action' => 'modify', 'finding' => 'responsible', 'penalty' => 'written_warning', 'reason' => 'Reconsidered after remand.']);
    expect(dapDeadline($case, Regulation201::APPEAL_REMAND_RECONSIDERATION)->completed_at)->not->toBeNull()
        ->and($case->refresh()->status)->toBe(DisciplinaryCaseStatus::Decided);
});

it('refuses an appeal on an undelivered decision, a second appeal, or a date before delivery', function (): void {
    $case = dapDelivered($this, 'written_warning', null);

    expect(fn () => $this->cases->recordAppealNotice($case, $this->s->officer, ['notice_received_at' => '2026-10-05 08:00', 'record_copy_requested' => false]))
        ->toThrow(ValidationException::class);

    $this->cases->recordAppealNotice($case, $this->s->officer, ['notice_received_at' => '2026-10-06 11:00', 'record_copy_requested' => false]);
    expect(fn () => $this->cases->recordAppealNotice($case->refresh(), $this->s->officer, ['notice_received_at' => '2026-10-06 11:30', 'record_copy_requested' => false]))
        ->toThrow(ValidationException::class);

    // The accused cannot record the institution's side.
    expect(fn () => $this->cases->recordAppealFiling($case->refresh(), $this->s->accused, '2026-10-06', null))
        ->toThrow(AuthorizationException::class);
});
