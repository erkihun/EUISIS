<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Reminders;

use DateTimeImmutable;

/**
 * Which reminder is due for one deadline. A run sends only the LATEST stage
 * whose time has come, so a scheduler that was down for a week sends one
 * "overdue" notice on recovery, never the whole backlog; the stage code is
 * part of the delivery key, so a repeated run sends nothing twice.
 *
 * Defaults: 7, 3 and 1 day before, on the due date, once overdue, and an
 * escalation after 3 working days (the caller computes that instant with the
 * working calendar and passes it in).
 */
final class ReminderStages
{
    public const DEFAULT_RULES = ['days_before' => [7, 3, 1], 'on_due_date' => true, 'when_overdue' => true, 'escalate_after_working_days' => 3];

    /**
     * @param  array{days_before?: list<int>, on_due_date?: bool, when_overdue?: bool, escalate_after_working_days?: int|null}  $rules
     * @return ?string the stage code: before_7 … before_1, due, overdue, escalated; null when none is due yet
     */
    public static function current(array $rules, DateTimeImmutable $dueAt, DateTimeImmutable $now, ?DateTimeImmutable $escalateAt = null): ?string
    {
        $rules += self::DEFAULT_RULES;

        if ($now > $dueAt) {
            if ($escalateAt !== null && $now >= $escalateAt && $rules['escalate_after_working_days'] !== null) {
                return 'escalated';
            }

            return $rules['when_overdue'] ? 'overdue' : null;
        }

        $secondsLeft = $dueAt->getTimestamp() - $now->getTimestamp();
        if ($rules['on_due_date'] && $secondsLeft < 86400) {
            return 'due';
        }
        $days = array_values(array_unique(array_map(intval(...), $rules['days_before'])));
        sort($days);
        foreach ($days as $day) {
            if ($day > 0 && $secondsLeft <= $day * 86400) {
                return "before_{$day}";
            }
        }

        return null;
    }
}
