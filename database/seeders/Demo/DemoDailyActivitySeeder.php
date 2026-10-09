<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\DailyActivityDayStatus;
use App\Models\Employee;
use App\Models\PositionService;
use App\Models\PositionServiceSubService;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Models\User;
use App\Services\DailyActivity\DailyActivityCalendarService;
use App\Services\DailyActivity\DailyActivityService;
use App\Services\DailyActivity\DailyActivitySettings;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Carbon;

/**
 * A few Daily Activity logs through DailyActivityService, on the latest
 * working day the backdating rule still accepts: a draft, a submitted log,
 * one returned for correction and one approved by the Organization 1 head's
 * portal account. The service
 * derives organization, unit, position and status; working-day and
 * backdating rules apply as for employees.
 *
 * Work execution register: the approved employee's position gets the
 * synthetic structure Employee Administration → Employee Transfer
 * Processing → Review Transfer Application, with an approved standard
 * (quantity 10, time 30 minutes, quality 100), and the approved day records
 * one measured execution of it (8 done in 40 minutes at quality 90: 80%,
 * 75%, 90%, aggregate 81.67%), calculated by the server.
 */
class DemoDailyActivitySeeder extends DemoSeeder
{
    /** employee key => draft | submitted | returned | approved */
    private const LOGS = ['E-1-6' => 'approved', 'E-1-2' => 'submitted', 'E-1-4' => 'returned', 'E-1-3' => 'draft'];

    public function run(DailyActivityService $service, DailyActivityCalendarService $calendar, DailyActivitySettings $settings): void
    {
        if (! $settings->enabled()) {
            $this->command?->warn('Daily Activity is disabled in settings: SKIPPED.');

            return;
        }

        $reviewer = DemoDataset::requireUser('demo.org1.manager@example.test');
        $measuredTask = $this->workStructure(DemoDataset::requireEmployee('E-1-6'));

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
                ], ...($key === 'E-1-6' && $measuredTask !== null ? [[
                    'task_id' => $measuredTask->id,
                    'title' => 'Synthetic demo: reviewed transfer applications',
                    'output_result' => '8 transfer applications reviewed (demo)',
                    'progress_status' => 'completed',
                    'started_at' => '13:00',
                    'ended_at' => '13:40',
                    'quantity' => '8',
                    'actual_quality' => '90',
                ]] : [])],
                'late_reason' => 'Synthetic demo: entered by the demo seeder',
            ], submit: $state !== 'draft');

            if ($state === 'approved') {
                $service->approve($reviewer, $log, 'Synthetic demo review: approved');
            }
            if ($state === 'returned') {
                $service->returnForCorrection($reviewer, $log, 'Synthetic demo review: please add the output produced.');
            }
        }
    }

    /**
     * Synthetic main service → sub-service → main task → approved standard on
     * the employee's position. Idempotent. Null when the employee has no
     * position on file.
     */
    private function workStructure(Employee $employee): ?PositionServiceTask
    {
        $assignment = $employee->currentAssignment;
        if ($assignment?->position_id === null) {
            return null;
        }

        $service = PositionService::query()->firstOrCreate(
            ['position_id' => $assignment->position_id, 'service_no' => 'DEMO-EMP-ADMIN'],
            ['organization_id' => $assignment->organization_id, 'name_en' => 'DEMO Employee Administration', 'name_am' => 'የሠራተኛ አስተዳደር (ማሳያ)', 'is_active' => true],
        );
        $sub = PositionServiceSubService::query()->firstOrCreate(
            ['position_service_id' => $service->id, 'code' => 'DEMO-SS-TRANSFER'],
            ['organization_id' => $service->organization_id, 'name_en' => 'DEMO Employee Transfer Processing', 'name_am' => 'የሠራተኛ ዝውውር ሂደት (ማሳያ)', 'is_active' => true],
        );
        $task = PositionServiceTask::query()->firstOrCreate(
            ['sub_service_id' => $sub->id, 'code' => 'DEMO-T-REVIEW'],
            ['position_service_id' => $service->id, 'organization_id' => $service->organization_id, 'name_en' => 'DEMO Review Transfer Application', 'name_am' => 'የዝውውር ማመልከቻ መገምገም (ማሳያ)', 'is_active' => true],
        );
        PositionServiceTaskStandard::query()->firstOrCreate(
            ['task_id' => $task->id, 'version_no' => 1],
            [
                'organization_id' => $service->organization_id, 'status' => 'approved',
                'standard_measure' => 'Synthetic demo: 10 applications a day, 30 minutes, all complete and correct',
                'bpr_reference' => 'DEMO-BPR-HR-01',
                'planned_quantity' => 10, 'quantity_unit' => 'applications',
                'planned_time_minutes' => 30,
                'planned_quality' => 100, 'quality_unit' => '%',
                'quality_measure' => 'Synthetic demo: share of reviewed applications complete and correct',
                'quality_source' => 'employee',
                'effective_from' => '2020-01-01',
                'approved_at' => now(),
            ],
        );

        return $task;
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
