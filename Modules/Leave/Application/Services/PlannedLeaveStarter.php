<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;
use Modules\Leave\Domain\Models\LeavePlan;

/**
 * Counts approved planned leave by itself (owner rule, 2026-11-27): when a
 * period of the leave schedule was approved and the employee did not ask to
 * change it before its first day, the system records it as leave on that
 * day and takes the days from the balance. The approval of the schedule is
 * the decision; no second request or decision is asked for.
 *
 * Safe to run any number of times: a period is counted once (it then points
 * at its leave request). A period that cannot be counted (not enough days
 * left, the days are already on another request, the employee is no longer
 * in service) keeps the reason, is tried again on the next run, and shows
 * on the leave schedule page for the HR officer.
 */
class PlannedLeaveStarter
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly LeaveRequestService $requests,
        private readonly LeaveSettings $settings,
    ) {}

    /**
     * Count every due period, or only those of one employee.
     *
     * @return array{counted: int, failed: int}
     */
    public function run(?Employee $onlyFor = null): array
    {
        // Settings page: automatic counting can be switched off; planned leave is then asked for as a request.
        if (! $this->settings->bool('schedule_enabled') || ! $this->settings->bool('schedule_auto_count')) {
            return ['counted' => 0, 'failed' => 0];
        }
        $today = $this->calendar->today();
        $counted = 0;
        $failed = 0;

        LeavePlan::query()
            ->where('status', LeavePlan::STATUS_APPROVED)->whereNull('leave_request_id')
            ->whereDate('starts_on', '<=', $today)
            ->when($onlyFor !== null, fn ($query) => $query->where('employee_id', $onlyFor->id))
            ->orderBy('starts_on')->orderBy('id')
            ->chunkById(100, function ($plans) use (&$counted, &$failed): void {
                foreach ($plans as $plan) {
                    $this->start($plan) ? $counted++ : $failed++;
                }
            });

        return ['counted' => $counted, 'failed' => $failed];
    }

    private function start(LeavePlan $plan): bool
    {
        try {
            $this->requests->startFromPlan($plan);

            return true;
        } catch (ValidationException $exception) {
            $reason = (string) collect($exception->errors())->flatten()->first();
        } catch (\Throwable $exception) {
            report($exception);
            $reason = __('leave::messages.errors.plan_not_counted');
        }
        if ($plan->auto_error !== $reason) {
            $plan->forceFill(['auto_error' => mb_substr($reason, 0, 500)])->save();
        }

        return false;
    }
}
