<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Enums\DailyActivityDayStatus;
use App\Enums\EmployeeStatus;
use App\Models\DailyActivityLog;
use App\Models\DailyActivityReminder;
use App\Models\Employee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Scheduled reminders for missing daily activity.
 *
 *   end_of_day -> after the configured reminder time, for today's required
 *                 day that is still unsubmitted (none, or only a draft)
 *   next_day   -> during the next working day, for the previous working
 *                 day that is still missing
 *
 * Only days the calendar service classifies as required are considered, so
 * leave, public holidays and non-working days never produce a reminder.
 * Each (employee, date, type) is recorded before sending, which makes the
 * job safe to run every few minutes: it sends at most once.
 */
class DailyActivityReminderService
{
    /** Morning reminders wait until the working day has plausibly started. */
    public const NEXT_DAY_NOT_BEFORE = '08:30';

    /** Employees per batch; each batch is a few bounded queries and queued notifications. */
    public int $chunkSize = 300;

    /** A log in one of these states is done for the day: never reminded. */
    private const DONE_STATUSES = ['submitted', 'under_review', 'resubmitted', 'approved'];

    public function __construct(
        private readonly DailyActivitySettings $settings,
        private readonly DailyActivityCalendarService $calendar,
        private readonly WorkCalendarService $workCalendar,
        private readonly DailyActivityNotifier $notifier,
    ) {}

    /** @return array{end_of_day: int, next_day: int} */
    public function sendDue(?Carbon $now = null): array
    {
        $sent = ['end_of_day' => 0, 'next_day' => 0];

        if (! $this->settings->enabled() || ! $this->settings->autoReminderEnabled() || ! $this->settings->requireDailySubmission()) {
            return $sent;
        }

        $now = ($now ?? $this->settings->now())->copy()->setTimezone($this->settings->timezone());
        $today = Carbon::parse($now->toDateString());
        $clock = $now->format('H:i');

        if ($clock >= $this->settings->reminderTime() && $this->workCalendar->isWorkingDay($today)) {
            $sent['end_of_day'] = $this->remind($today, 'end_of_day', fn (DailyActivityDayStatus $status): bool => in_array($status, [DailyActivityDayStatus::Required, DailyActivityDayStatus::Draft], true));
        }

        $previous = $this->workCalendar->previousWorkingDay($today);
        if ($clock >= self::NEXT_DAY_NOT_BEFORE && $previous !== null && $this->workCalendar->isWorkingDay($today)) {
            $sent['next_day'] = $this->remind($previous, 'next_day', fn (DailyActivityDayStatus $status): bool => in_array($status, [DailyActivityDayStatus::Missing, DailyActivityDayStatus::Draft], true));
        }

        return $sent;
    }

    /** @param callable(DailyActivityDayStatus): bool $shouldRemind */
    private function remind(Carbon $date, string $type, callable $shouldRemind): int
    {
        $count = 0;

        Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->whereNotNull('email')
            // Already evaluated for this date and type (reminded or skipped).
            ->whereNotIn('id', DailyActivityReminder::query()
                ->where('activity_date', '>=', $date->toDateString())
                ->where('activity_date', '<=', $date->toDateString().' 23:59:59')
                ->where('reminder_type', $type)
                ->select('employee_id'))
            // Already submitted: done for the day, excluded by the query itself
            // so a 3M workforce is not re-walked every run (risk register SCL-7).
            ->whereNotIn('id', DailyActivityLog::query()
                ->where('activity_date', '>=', $date->toDateString())
                ->where('activity_date', '<=', $date->toDateString().' 23:59:59')
                ->whereIn('status', self::DONE_STATUSES)
                ->select('employee_id'))
            // Keyset paging (id > last), not OFFSET: every claim below removes
            // an employee from this query's result, which would make OFFSET
            // pages skip employees who were never reached.
            ->chunkById($this->chunkSize, function ($employees) use ($date, $type, $shouldRemind, &$count): void {
                $users = $this->notifier->usersFor($employees);

                $skipped = [];
                foreach ($this->calendar->rows($employees, $date, $date) as $row) {
                    if (! $shouldRemind($row['status'])) {
                        // Evaluated, nothing due (leave, not required...): record it
                        // so the next run does not evaluate this employee again.
                        $skipped[] = [
                            'id' => (string) Str::uuid7(),
                            'employee_id' => $row['employee']->id, 'activity_date' => $date->toDateString(),
                            'reminder_type' => $type, 'sent_at' => now(), 'skipped' => true,
                        ];

                        continue;
                    }

                    if (! $this->claim($row['employee'], $date, $type)) {
                        continue;
                    }

                    if ($this->notifier->notifyUser($users[$row['employee']->id] ?? null, $row['employee'], $type, $date->toDateString())) {
                        $count++;
                    }
                }

                // One bulk insert per batch; a concurrent run's row wins harmlessly.
                if ($skipped !== []) {
                    DailyActivityReminder::query()->insertOrIgnore($skipped);
                }
            });

        return $count;
    }

    /** Record the reminder first; a duplicate means another run already sent it. */
    private function claim(Employee $employee, Carbon $date, string $type): bool
    {
        try {
            DailyActivityReminder::query()->create([
                'employee_id' => $employee->id,
                'activity_date' => $date->toDateString(),
                'reminder_type' => $type,
                'sent_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
