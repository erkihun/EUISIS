<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Models\StatutoryDeadline;
use App\Services\Calendar\WorkingDayCalculator;
use App\Support\Legal\Regulation201;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The one place statutory (Regulation 201/2018) deadlines are calculated.
 * Working days come from WorkingDayCalculator with the civil-service work week
 * and the shared public-holiday list; no controller or model does date math.
 *
 * snapshot() stores the result. A stored due date is never recalculated: a
 * later holiday or work-week change affects new milestones only.
 */
final class StatutoryDeadlineService
{
    public function __construct(private readonly WorkingDayCalculator $calendar) {}

    /** @return list<int> */
    public function workWeekDays(): array
    {
        $days = array_values(array_filter(
            array_map('intval', (array) config('civil_service.work_week_days', [1, 2, 3, 4, 5])),
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        ));

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    /** End of the last day allowed by $ruleKey when the period starts at $start. */
    public function dueAt(string $ruleKey, Carbon $start): Carbon
    {
        $rule = Regulation201::rule($ruleKey);

        $due = match ($rule['unit']) {
            Regulation201::WORKING_DAYS => $this->calendar->addWorkingDays($start, $rule['amount'], $this->workWeekDays()),
            Regulation201::MONTHS => $start->copy()->addMonthsNoOverflow($rule['amount'])->endOfDay(),
            default => $this->calendar->addCalendarDays($start, $rule['amount']),
        };

        // Art. 38(4): a period ending on a non-working day runs to the next working day.
        if ($rule['roll_forward']) {
            while (! $this->calendar->isWorkingDay($due, $this->workWeekDays())) {
                $due = $due->copy()->addDay()->endOfDay();
            }
        }

        return $due;
    }

    /**
     * True when at least $ruleKey's working days separate $from and $to, e.g.
     * a summons served at least 10 working days before the hearing (Art. 18(2)).
     */
    public function hasMinimumNotice(string $ruleKey, Carbon $from, Carbon $to): bool
    {
        $rule = Regulation201::rule($ruleKey);

        return $rule['unit'] === Regulation201::WORKING_DAYS
            ? $this->calendar->workingDaysBetween($from, $to->copy()->subDay(), $this->workWeekDays()) >= $rule['amount']
            : $to->copy()->startOfDay()->gte($this->dueAt($ruleKey, $from)->startOfDay());
    }

    /**
     * Record an immutable deadline for $subject. Re-snapshotting the same open
     * rule supersedes the earlier row instead of editing it.
     */
    public function snapshot(Model $subject, string $ruleKey, Carbon $start): StatutoryDeadline
    {
        $rule = Regulation201::rule($ruleKey);

        StatutoryDeadline::query()
            ->whereMorphedTo('subject', $subject)
            ->where('rule_key', $ruleKey)
            ->whereNull('completed_at')
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);

        return StatutoryDeadline::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'rule_key' => $ruleKey,
            'legal_reference' => 'Regulation 201/2018 '.$rule['article'],
            'basis' => $rule['unit'],
            'amount' => $rule['amount'],
            'started_at' => $start,
            'due_at' => $this->dueAt($ruleKey, $start),
        ]);
    }

    /** Mark the open deadline for $ruleKey met (or late) at $at. */
    public function complete(Model $subject, string $ruleKey, ?Carbon $at = null): void
    {
        StatutoryDeadline::query()
            ->whereMorphedTo('subject', $subject)
            ->where('rule_key', $ruleKey)
            ->whereNull('completed_at')
            ->whereNull('superseded_at')
            ->update(['completed_at' => $at ?? now()]);
    }
}
