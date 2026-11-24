<?php

declare(strict_types=1);

use Modules\Reporting\Domain\Calendar\EthiopianDate;
use Modules\Reporting\Domain\Calendar\PeriodCalculator;
use Modules\Reporting\Domain\Compliance\ComplianceTally;
use Modules\Reporting\Domain\Compliance\ObligationFacts;
use Modules\Reporting\Domain\Compliance\TimelinessRule;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Enums\CalendarBasis;
use Modules\Reporting\Domain\Enums\Frequency;
use Modules\Reporting\Domain\Enums\LatePolicy;
use Modules\Reporting\Domain\Enums\ReviewDecision;
use Modules\Reporting\Domain\Enums\Timeliness;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Domain\Reminders\ReminderStages;
use Modules\Reporting\Domain\Workflow\WorkflowRules;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;

/*
 * The module's pure rules, with no framework and no database: the Ethiopian
 * calendar and reporting periods, timeliness, the compliance tally, the
 * workflow guards and the reminder stages.
 */

function at(string $utc): DateTimeImmutable
{
    return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
}

// ── Ethiopian calendar ────────────────────────────────────────────────────

test('Ethiopian dates convert to and from Gregorian', function (string $gregorian, int $year, int $month, int $day): void {
    $date = EthiopianDate::fromIso($gregorian);

    expect([$date->year, $date->month, $date->day])->toBe([$year, $month, $day])
        ->and((new EthiopianDate($year, $month, $day))->toIso())->toBe($gregorian);
})->with([
    'new year 2010' => ['2017-09-11', 2010, 1, 1],
    'new year after a leap year (2016)' => ['2023-09-12', 2016, 1, 1],
    'Pagume 6 of leap year 2015' => ['2023-09-11', 2015, 13, 6],
    'Pagume 5 of 2018' => ['2026-09-10', 2018, 13, 5],
    'new year 2019' => ['2026-09-11', 2019, 1, 1],
    'Hidar 11, 2019' => ['2026-11-20', 2019, 3, 11],
    'Tahsas 29 (Christmas eve)' => ['2027-01-07', 2019, 4, 29],
    'Gregorian leap day' => ['2024-02-29', 2016, 6, 21],
]);

test('Pagume has six days only in a leap year (year mod 4 = 3)', function (): void {
    expect(EthiopianDate::isLeapYear(2015))->toBeTrue()
        ->and(EthiopianDate::isLeapYear(2019))->toBeTrue()
        ->and(EthiopianDate::isLeapYear(2018))->toBeFalse()
        ->and(EthiopianDate::daysInMonth(2015, 13))->toBe(6)
        ->and(EthiopianDate::daysInMonth(2018, 13))->toBe(5)
        ->and(EthiopianDate::daysInMonth(2018, 12))->toBe(30)
        ->and(fn () => new EthiopianDate(2018, 13, 6))->toThrow(InvalidArgumentException::class);
});

test('every day of several Ethiopian years round-trips', function (): void {
    $jdn = EthiopianDate::isoToJdn('2022-09-11');
    for ($i = 0; $i < 1900; $i++) {
        $iso = EthiopianDate::jdnToIso($jdn + $i);
        expect(EthiopianDate::fromIso($iso)->toIso())->toBe($iso);
    }
});

// ── Reporting periods ─────────────────────────────────────────────────────

test('an Ethiopian month is thirty days and the Nehase period carries Pagume', function (): void {
    $periods = new PeriodCalculator;
    $tikimt = $periods->containing(Frequency::Monthly, CalendarBasis::Ethiopian, '2026-10-20');
    $nehase2018 = $periods->containing(Frequency::Monthly, CalendarBasis::Ethiopian, '2026-08-20');
    $pagume2018 = $periods->containing(Frequency::Monthly, CalendarBasis::Ethiopian, '2026-09-08');
    $nehase2019 = $periods->containing(Frequency::Monthly, CalendarBasis::Ethiopian, '2027-09-11');

    expect([$tikimt->key, $tikimt->startDate, $tikimt->endDate])->toBe(['EC-2019-M02', '2026-10-11', '2026-11-09'])
        // 2018 is not a leap year: Nehase 1 – Pagume 5 = 35 days.
        ->and([$nehase2018->key, $nehase2018->startDate, $nehase2018->endDate])->toBe(['EC-2018-M12', '2026-08-07', '2026-09-10'])
        ->and($pagume2018->key)->toBe('EC-2018-M12')
        // 2019 is a leap year: the same period is one day longer, and Pagume 6 belongs to it.
        ->and([$nehase2019->key, $nehase2019->startDate, $nehase2019->endDate])->toBe(['EC-2019-M12', '2027-08-07', '2027-09-11'])
        ->and($periods->following($nehase2019, Frequency::Monthly, CalendarBasis::Ethiopian)->startDate)->toBe('2027-09-12');
});

test('Ethiopian quarters follow the fiscal year that starts in Hamle', function (): void {
    $periods = new PeriodCalculator;
    // Hamle 1, 2018 opens budget year 2019; its first quarter runs to Meskerem 30, 2019 and holds Pagume.
    $q1 = $periods->containing(Frequency::Quarterly, CalendarBasis::Ethiopian, '2026-09-09', 11);
    $q2 = $periods->following($q1, Frequency::Quarterly, CalendarBasis::Ethiopian, 11);
    $year = $periods->containing(Frequency::Annual, CalendarBasis::Ethiopian, '2026-11-20', 11);
    $half = $periods->containing(Frequency::Semiannual, CalendarBasis::Ethiopian, '2027-02-01', 11);

    expect([$q1->key, $q1->startDate, $q1->endDate])->toBe(['EC-FY2019-Q1', '2026-07-08', '2026-10-10'])
        ->and([$q2->key, $q2->startDate, $q2->endDate])->toBe(['EC-FY2019-Q2', '2026-10-11', '2027-01-08'])
        ->and([$year->key, $year->startDate, $year->endDate])->toBe(['EC-FY2019', '2026-07-08', '2027-07-07'])
        ->and($half->key)->toBe('EC-FY2019-H2');
});

test('consecutive periods never overlap and never leave a gap, across leap years', function (Frequency $frequency, CalendarBasis $basis, int $fiscalStart): void {
    $periods = new PeriodCalculator;
    $period = $periods->containing($frequency, $basis, '2022-01-01', $fiscalStart);
    for ($i = 0; $i < 80; $i++) {
        $next = $periods->following($period, $frequency, $basis, $fiscalStart);
        expect(EthiopianDate::isoToJdn($next->startDate))->toBe(EthiopianDate::isoToJdn($period->endDate) + 1)
            ->and($next->key)->not->toBe($period->key);
        $period = $next;
    }
})->with([
    'Ethiopian months' => [Frequency::Monthly, CalendarBasis::Ethiopian, 1],
    'Ethiopian quarters from Hamle' => [Frequency::Quarterly, CalendarBasis::Ethiopian, 11],
    'Ethiopian halves from Hamle' => [Frequency::Semiannual, CalendarBasis::Ethiopian, 11],
    'Gregorian months' => [Frequency::Monthly, CalendarBasis::Gregorian, 1],
    'Gregorian quarters from July' => [Frequency::Quarterly, CalendarBasis::Gregorian, 7],
    'weeks' => [Frequency::Weekly, CalendarBasis::Gregorian, 1],
]);

test('Gregorian periods and weeks', function (): void {
    $periods = new PeriodCalculator;
    $february = $periods->containing(Frequency::Monthly, CalendarBasis::Gregorian, '2028-02-10');
    $quarter = $periods->containing(Frequency::Quarterly, CalendarBasis::Gregorian, '2026-11-20', 7);
    $week = $periods->containing(Frequency::Weekly, CalendarBasis::Gregorian, '2026-11-20');

    expect([$february->startDate, $february->endDate])->toBe(['2028-02-01', '2028-02-29'])
        ->and([$quarter->key, $quarter->startDate, $quarter->endDate])->toBe(['GC-FY2027-Q2', '2026-10-01', '2026-12-31'])
        // 20 November 2026 is a Friday; the week runs Monday to Sunday.
        ->and([$week->startDate, $week->endDate])->toBe(['2026-11-16', '2026-11-22']);
});

// ── Timeliness ────────────────────────────────────────────────────────────

test('a submission at the exact deadline is on time; one second later is late', function (): void {
    $due = at('2026-11-14 14:30:00');
    $asOf = at('2026-11-20 00:00:00');

    expect(TimelinessRule::of($due, $due, $asOf))->toBe(Timeliness::OnTime)
        ->and(TimelinessRule::of($due->modify('+1 second'), $due, $asOf))->toBe(Timeliness::Late)
        ->and(TimelinessRule::of(null, $due, $asOf))->toBe(Timeliness::Overdue)
        ->and(TimelinessRule::of(null, $due, $due))->toBe(Timeliness::Overdue)
        ->and(TimelinessRule::of(null, $due, $due->modify('-1 second')))->toBe(Timeliness::NotDue)
        ->and(TimelinessRule::of($due->modify('-3 days'), $due, $due->modify('-1 day')))->toBe(Timeliness::Early);
});

test('a submission made after the as-of time has not happened yet', function (): void {
    $due = at('2026-11-14 14:30:00');

    // Submitted on the 16th; seen from the 15th the report was overdue, from the 17th it was late.
    expect(TimelinessRule::of(at('2026-11-16 08:00:00'), $due, at('2026-11-15 00:00:00')))->toBe(Timeliness::Overdue)
        ->and(TimelinessRule::of(at('2026-11-16 08:00:00'), $due, at('2026-11-17 00:00:00')))->toBe(Timeliness::Late)
        ->and(TimelinessRule::daysOverdue(at('2026-11-16 08:00:00'), $due, at('2026-11-30 00:00:00')))->toBe(2)
        ->and(TimelinessRule::daysOverdue(null, $due, at('2026-11-20 14:30:00')))->toBe(6)
        ->and(TimelinessRule::daysOverdue($due, $due, at('2026-11-30 00:00:00')))->toBe(0);
});

// ── Compliance tally ──────────────────────────────────────────────────────

test('rates use due-required obligations as the denominator and count each obligation once', function (): void {
    $asOf = at('2026-11-20 00:00:00');
    $due = at('2026-11-14 14:30:00');
    $future = at('2026-12-14 14:30:00');
    $facts = fn (Applicability $a, WorkflowStatus $w, DateTimeImmutable $d, ?DateTimeImmutable $first = null): ObligationFacts => new ObligationFacts($a, $w, $d, $d, $first);

    $tally = new ComplianceTally($asOf);
    $tally->add($facts(Applicability::Required, WorkflowStatus::Approved, $due, $due->modify('-1 day')));   // on time, approved
    $tally->add($facts(Applicability::Required, WorkflowStatus::UnderReview, $due, $due->modify('+1 day'))); // late
    $tally->add($facts(Applicability::Required, WorkflowStatus::NotStarted, $due));                          // missing
    $tally->add($facts(Applicability::Required, WorkflowStatus::Draft, $due));                               // a draft is not a submission
    $tally->add($facts(Applicability::Exempted, WorkflowStatus::NotStarted, $due));                          // out of the denominator
    $tally->add($facts(Applicability::Cancelled, WorkflowStatus::NotStarted, $due));
    $tally->add($facts(Applicability::Required, WorkflowStatus::Submitted, $future, $asOf->modify('-1 day'))); // early, not yet due
    $tally->add($facts(Applicability::Required, WorkflowStatus::NotStarted, $future));

    $c = $tally->counts();
    expect($c['expected'])->toBe(8)
        ->and($c['applicable'])->toBe(6)
        ->and($c['due_required'])->toBe(4)
        ->and([$c['submitted_on_time'], $c['submitted_late'], $c['overdue_unsubmitted']])->toBe([1, 1, 2])
        ->and([$c['not_yet_due'], $c['early_submissions']])->toBe([2, 1])
        ->and([$c['exempted'], $c['cancelled']])->toBe([1, 1])
        ->and($tally->rates())->toBe(['submission_rate' => 0.5, 'on_time_rate' => 0.25, 'approval_rate' => 1 / 3]);

    // The partitions named by the tally add up exactly.
    foreach (['expected', 'applicable', 'due_required'] as $total) {
        expect(array_sum(array_map(fn (string $part): int => $c[$part], ComplianceTally::partitions()[$total])))->toBe($c[$total]);
    }
    expect(array_sum(array_map(fn (string $part): int => $c[$part], ComplianceTally::partitions()['applicable_by_workflow'])))->toBe($c['applicable']);
});

test('a zero denominator gives no rate rather than 0 or 100 percent', function (): void {
    $tally = new ComplianceTally(at('2026-11-20 00:00:00'));
    $tally->add(new ObligationFacts(Applicability::Exempted, WorkflowStatus::NotStarted, at('2026-11-14'), at('2026-11-14')));

    expect($tally->rates())->toBe(['submission_rate' => null, 'on_time_rate' => null, 'approval_rate' => null]);
});

test('an extension changes adjusted compliance but compliance against the original deadline is kept', function (): void {
    $original = at('2026-11-14 14:30:00');
    $extended = at('2026-11-18 14:30:00');
    $tally = new ComplianceTally(at('2026-11-20 00:00:00'));
    $tally->add(new ObligationFacts(Applicability::Required, WorkflowStatus::Submitted, $original, $extended, at('2026-11-16 09:00:00')));

    expect($tally->counts()['submitted_on_time'])->toBe(1)
        ->and($tally->counts()['on_time_by_original_deadline'])->toBe(0);
});

// ── Workflow guards ───────────────────────────────────────────────────────

test('the late policy decides what happens after the deadline', function (): void {
    $due = at('2026-11-14 14:30:00');
    $after = $due->modify('+1 hour');
    $submit = fn (LatePolicy $policy, ?string $reason, bool $first = true, WorkflowStatus $status = WorkflowStatus::Draft): bool => WorkflowRules::assertSubmittable($status, Applicability::Required, null, $due, $after, $policy, $first, $reason);

    expect($submit(LatePolicy::Accept, null))->toBeTrue()
        ->and($submit(LatePolicy::AcceptWithReason, 'Power cut'))->toBeTrue()
        ->and(fn () => $submit(LatePolicy::AcceptWithReason, ' '))->toThrow(WorkflowViolation::class, 'late_reason_required')
        ->and(fn () => $submit(LatePolicy::Block, 'Any'))->toThrow(WorkflowViolation::class, 'late_blocked')
        // A resubmission after a return is never refused by the late policy.
        ->and($submit(LatePolicy::Block, null, false, WorkflowStatus::Returned))->toBeTrue()
        ->and(fn () => $submit(LatePolicy::Accept, null, true, WorkflowStatus::Submitted))->toThrow(WorkflowViolation::class, 'not_editable');
});

test('review decisions follow the workflow', function (): void {
    $decide = fn (ReviewDecision $d, bool $last, ?string $reason = null, bool $own = false, bool $allowOwn = false, WorkflowStatus $from = WorkflowStatus::UnderReview): WorkflowStatus => WorkflowRules::afterDecision($from, $d, $last, $reason, $own, $allowOwn);

    expect($decide(ReviewDecision::Approve, false))->toBe(WorkflowStatus::UnderReview)
        ->and($decide(ReviewDecision::Approve, true))->toBe(WorkflowStatus::Approved)
        ->and($decide(ReviewDecision::Return, false, 'Add the table'))->toBe(WorkflowStatus::Returned)
        ->and($decide(ReviewDecision::Reject, true, 'Wrong period'))->toBe(WorkflowStatus::Rejected)
        ->and(fn () => $decide(ReviewDecision::Return, false, ''))->toThrow(WorkflowViolation::class, 'reason_required')
        ->and(fn () => $decide(ReviewDecision::Reject, true))->toThrow(WorkflowViolation::class, 'reason_required')
        ->and(fn () => $decide(ReviewDecision::Approve, true, null, true))->toThrow(WorkflowViolation::class, 'self_review_forbidden')
        ->and($decide(ReviewDecision::Approve, true, null, true, true))->toBe(WorkflowStatus::Approved)
        ->and(fn () => $decide(ReviewDecision::Approve, true, null, false, false, WorkflowStatus::Draft))->toThrow(WorkflowViolation::class, 'not_awaiting_decision')
        ->and(fn () => WorkflowRules::assertReopenable(WorkflowStatus::Approved, ''))->toThrow(WorkflowViolation::class, 'reason_required')
        ->and(fn () => WorkflowRules::assertReopenable(WorkflowStatus::Submitted, 'Why'))->toThrow(WorkflowViolation::class, 'not_final');
});

// ── Reminder stages ───────────────────────────────────────────────────────

test('one reminder stage is due at a time: 7, 3 and 1 day before, the due date, overdue, escalated', function (): void {
    $due = at('2026-11-14 14:30:00');
    $stage = fn (string $now, ?string $escalateAt = null): ?string => ReminderStages::current(ReminderStages::DEFAULT_RULES, $due, at($now), $escalateAt === null ? null : at($escalateAt));

    expect($stage('2026-11-01 08:00:00'))->toBeNull()
        ->and($stage('2026-11-07 14:30:00'))->toBe('before_7')
        ->and($stage('2026-11-10 08:00:00'))->toBe('before_7')
        ->and($stage('2026-11-11 15:00:00'))->toBe('before_3')
        ->and($stage('2026-11-13 14:31:00'))->toBe('due')
        ->and($stage('2026-11-13 14:00:00'))->toBe('before_3')
        ->and($stage('2026-11-14 14:30:00'))->toBe('due')
        ->and($stage('2026-11-14 14:30:01'))->toBe('overdue')
        ->and($stage('2026-11-18 08:00:00', '2026-11-19 14:30:00'))->toBe('overdue')
        ->and($stage('2026-11-19 14:30:00', '2026-11-19 14:30:00'))->toBe('escalated')
        ->and(ReminderStages::current(['days_before' => [], 'on_due_date' => false, 'when_overdue' => false, 'escalate_after_working_days' => null], $due, at('2026-11-30 00:00:00'), at('2026-11-19 00:00:00')))->toBeNull();
});
