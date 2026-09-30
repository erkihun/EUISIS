<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\DailyActivityDayStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\DailyActivity\DailyActivityCalendarService;
use App\Services\DailyActivity\DailyActivityService;
use App\Services\DailyActivity\DailyActivitySettings;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Carbon;

/**
 * A few Daily Activity logs through DailyActivityService, on the latest
 * working day the backdating rule still accepts: a draft, a submitted log,
 * and one approved by the Organization 1 head's portal account. The service
 * derives organization, unit, position and status; working-day and
 * backdating rules apply as for employees.
 */
class DemoDailyActivitySeeder extends DemoSeeder
{
    /** employee key => draft | submitted | approved */
    private const LOGS = ['E-1-6' => 'approved', 'E-1-2' => 'submitted', 'E-1-3' => 'draft'];

    public function run(DailyActivityService $service, DailyActivityCalendarService $calendar, DailyActivitySettings $settings): void
    {
        if (! $settings->enabled()) {
            $this->command?->warn('Daily Activity is disabled in settings: SKIPPED.');

            return;
        }

        $reviewer = DemoDataset::requireUser('demo.org1.manager@example.test');

        foreach (self::LOGS as $key => $state) {
            $employee = DemoDataset::requireEmployee($key);
            $date = $this->entryDate($employee, $calendar, $settings);
            if ($date === null) {
                $this->command?->warn("No open working day for {$key}'s Daily Activity: SKIPPED.");

                continue;
            }
            if ($service->findLog($employee, $date) !== null) {
                continue;
            }

            // Recorded by the employee's own portal account when there is one.
            $actor = User::query()->where('employee_id', $employee->id)->first()
                ?? DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);

            $log = $service->save($actor, $employee, $date, [
                'items' => [[
                    'activity_category' => 'service_delivery',
                    'title' => 'Synthetic demo task: prepared the weekly service summary',
                    'description' => 'Synthetic demo text — not a real record.',
                    'output_result' => 'Weekly summary drafted (demo)',
                    'progress_status' => 'completed',
                    'started_at' => '09:00',
                    'ended_at' => '11:30',
                ]],
                'late_reason' => 'Synthetic demo: entered by the demo seeder',
            ], submit: $state !== 'draft');

            if ($state === 'approved') {
                $service->approve($reviewer, $log, 'Synthetic demo review: approved');
            }
        }
    }

    /** The latest day on or before the first run that is open for entry. */
    private function entryDate(Employee $employee, DailyActivityCalendarService $calendar, DailyActivitySettings $settings): ?Carbon
    {
        $today = DemoDataset::anchor()->min($settings->today()->startOfDay());
        for ($back = 0; $back <= $settings->maxBackdateDays(); $back++) {
            $day = $today->copy()->subDays($back);
            $status = $calendar->dayStatus($employee, $day);
            if (! in_array($status, [DailyActivityDayStatus::Weekend, DailyActivityDayStatus::PublicHoliday, DailyActivityDayStatus::Leave,
                DailyActivityDayStatus::NotEmployed, DailyActivityDayStatus::NotAssigned, DailyActivityDayStatus::NotTracked, DailyActivityDayStatus::Future], true)) {
                return $day;
            }
        }

        return null;
    }
}
