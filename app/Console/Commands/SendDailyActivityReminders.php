<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DailyActivity\DailyActivityReminderService;
use Illuminate\Console\Command;

/**
 * Sends end-of-day and next-working-day Daily Activity reminders.
 *
 * Safe to run as often as the scheduler likes: each (employee, date, type)
 * is recorded before sending, so a reminder goes out at most once. Leave,
 * public holidays and non-working days are never reminded about.
 */
class SendDailyActivityReminders extends Command
{
    protected $signature = 'daily-activities:send-reminders';

    protected $description = 'Remind employees about missing daily activity on required working days';

    public function handle(DailyActivityReminderService $reminders): int
    {
        $sent = $reminders->sendDue();

        $this->info(sprintf(
            'Daily activity reminders sent: %d end-of-day, %d next-day.',
            $sent['end_of_day'],
            $sent['next_day'],
        ));

        return self::SUCCESS;
    }
}
