<?php

declare(strict_types=1);

use App\Support\Legal\Regulation201;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Discipline\Application\Services\DisciplinaryCaseService;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Modules\Discipline\Domain\Models\DisciplinarySuspension;
use Tests\Support\DisciplineScenario;

/*
 * Regulation 201/2018 Art. 12: suspension pending a disciplinary case — the
 * written reason (12(1)), the three-month limit on suspension from work and
 * salary (12(4)), and the withheld salary paid back without interest unless
 * the employee is dismissed (12(2)).
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = DisciplineScenario::build();
    $this->cases = app(DisciplinaryCaseService::class);
});

afterEach(fn () => Carbon::setTestNow());

function dsuCharged(object $test): DisciplinaryCase
{
    $s = $test->s;
    $case = $test->cases->open($s->officer, $s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious', 'summary' => 'Misuse of funds']);
    $test->cases->addCharge($case, $s->officer, ['title' => 'Misuse of funds', 'particulars' => 'Petty cash used privately.', 'occurred_on' => '2026-09-01', 'occurred_place' => 'Finance', 'violated_provision' => 'Proclamation 87/2017 Art. 95', 'evidence_list' => 'Cash book']);
    $test->cases->assignPanel($case, $s->officer, $s->committee->id);
    $test->cases->fileCharge($case, $s->officer);

    return $case->refresh();
}

function dsuSuspend(object $test, DisciplinaryCase $case, array $overrides = []): DisciplinarySuspension
{
    return $test->cases->suspend($case, $test->s->head, [
        'starts_on' => '2026-10-05', 'without_pay' => true, 'ends_on' => '2027-01-05',
        'reason' => 'The employee holds the cash book that is the main evidence.', 'served_at' => '2026-10-05 08:30', 'service_method' => 'personal',
        ...$overrides,
    ]);
}

/** Delivered decision with the given penalty, then closed. */
function dsuDecideAndClose(object $test, DisciplinaryCase $case, string $penalty): void
{
    $s = $test->s;
    $test->cases->recordResponse($case, $s->accused, ['pleas' => $case->charges()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['plea' => 'admitted']])->all(), 'acknowledged_name' => 'A']);
    $test->cases->recordFinalStatement($case->refresh(), $s->accused, 'Nothing to add.', false);
    $seat = fn ($user) => $case->members()->where('employee_id', $user->employee_id)->firstOrFail()->id;
    $test->cases->adoptRecommendation($case->refresh(), $s->chair, [
        'finding' => 'responsible', 'penalty' => $penalty, 'gravity_assessment' => 'g', 'conduct_assessment' => 'c', 'prior_record_assessment' => 'p', 'reasoning' => 'r',
        'votes' => [$seat($s->chair) => ['vote' => 'for'], $seat($s->appointed) => ['vote' => 'for'], $seat($s->elected) => ['vote' => 'for']],
    ]);
    $test->cases->decide($case->refresh(), $s->head, ['action' => 'approve', 'reason' => 'Agreed.']);
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00'));
    $test->cases->recordDelivery($case->refresh(), $s->officer, ['delivered_at' => '2026-10-06 10:00', 'method' => 'personal']);
    $test->cases->close($case->refresh(), $s->officer);
}

it('limits a suspension from work and salary to three months', function (): void {
    $case = dsuCharged($this);

    expect(fn () => dsuSuspend($this, $case, ['ends_on' => '2027-01-06']))->toThrow(ValidationException::class);
    expect(fn () => dsuSuspend($this, $case, ['ends_on' => null]))->toThrow(ValidationException::class);

    $suspension = dsuSuspend($this, $case);
    expect($suspension->without_pay)->toBeTrue()
        ->and($case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_SUSPENSION_MAX)->value('due_at'))->not->toBeNull()
        ->and(fn () => dsuSuspend($this, $case->refresh()))->toThrow(ValidationException::class, __('discipline.errors.suspension_in_force'));

    // A suspension with pay has no statutory limit and needs no end date.
    $other = dsuCharged($this);
    expect(dsuSuspend($this, $other, ['without_pay' => false, 'ends_on' => null])->ends_on)->toBeNull();
});

it('keeps the posting period when the order cannot be handed over', function (): void {
    $suspension = dsuSuspend($this, dsuCharged($this), ['service_method' => 'posted']);
    expect($suspension->posting_ends_on->toDateString())->toBe('2026-10-15');
});

it('lifts a suspension and closes its statutory clock', function (): void {
    $case = dsuCharged($this);
    dsuSuspend($this, $case);
    Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00'));

    $this->cases->liftSuspension($case->refresh(), $this->s->head, '2026-11-02', 'Evidence secured.');

    expect($case->refresh()->suspensionInForce())->toBeNull()
        ->and($case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_SUSPENSION_MAX)->value('completed_at'))->not->toBeNull();
});

it('makes the withheld salary due when the case ends without dismissal, and records its payment', function (): void {
    $case = dsuCharged($this);
    $suspension = dsuSuspend($this, $case);

    $this->cases->discontinue($case->refresh(), $this->s->officer, 'Charge withdrawn in writing.');
    $suspension->refresh();
    expect($suspension->lifted_on)->not->toBeNull()
        ->and($suspension->back_pay_status)->toBe(DisciplinarySuspension::BACK_PAY_DUE);

    $this->cases->recordSuspensionBackPay($case->refresh(), $this->s->officer, $suspension->id, '2026-10-05', 'Paid with the October payroll.');
    expect($suspension->refresh()->back_pay_status)->toBe(DisciplinarySuspension::BACK_PAY_PAID);
});

it('owes no withheld salary when the employee is dismissed', function (): void {
    $case = dsuCharged($this);
    $suspension = dsuSuspend($this, $case);

    dsuDecideAndClose($this, $case->refresh(), 'dismissal');

    expect($suspension->refresh()->back_pay_status)->toBe(DisciplinarySuspension::BACK_PAY_NOT_DUE);
});

it('owes the withheld salary when the penalty is less than dismissal', function (): void {
    $case = dsuCharged($this);
    $suspension = dsuSuspend($this, $case);

    dsuDecideAndClose($this, $case->refresh(), 'written_warning');

    expect($suspension->refresh()->back_pay_status)->toBe(DisciplinarySuspension::BACK_PAY_DUE);
});
