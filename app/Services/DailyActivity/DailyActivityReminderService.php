<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Enums\DailyActivityDayStatus;
use App\Enums\EmployeeStatus;
use App\Models\DailyActivityReminder;
use App\Models\Employee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

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

    private const CHUNK = 300;

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
            ->whereNotIn('id', DailyActivityReminder::query()
                ->where('activity_date', '>=', $date->toDateString())
                ->where('activity_date', '<=', $date->toDateString().' 23:59:59')
                ->where('reminder_type', $type)
                ->select('employee_id'))
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($employees) use ($date, $type, $shouldRemind, &$count): void {
                foreach ($this->calendar->rows($employees, $date, $date) as $row) {
                    if (! $shouldRemind($row['status'])) {
                        continue;
                    }

                    if (! $this->claim($row['employee'], $date, $type)) {
                        continue;
                    }

                    if ($this->notifier->notifyEmployee($row['employee'], $type, $date->toDateString())) {
                        $count++;
                    }
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
