<?php

declare(strict_types=1);

namespace Modules\Transfer\Infrastructure\Jobs;

use App\Models\Employee;
use App\Services\Employees\EmployeeUserResolver;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Modules\Transfer\Application\Notifications\TransferAnnouncementNotification;
use Modules\Transfer\Application\Services\TransferEligibilityService;
use Modules\Transfer\Domain\Models\TransferAnnouncement;
use Modules\Transfer\Domain\Models\TransferSetting;

/**
 * Queued and resumable: publishing never enumerates the workforce in HTTP,
 * and one run never walks the whole workforce either (risk register SCL-8).
 * Each run evaluates at most $batchesPerRun × $batchSize employees by key,
 * then queues the next run with its cursor — always well inside the queue
 * timeout, so the job is never re-delivered mid-run and re-sent.
 */
class NotifyEligibleTransferAudience implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public int $batchSize = 200;

    public int $batchesPerRun = 25;

    public function __construct(public readonly string $announcementId, public readonly ?string $afterEmployeeId = null)
    {
        $this->onQueue('long');
    }

    public function handle(
        TransferEligibilityService $eligibility,
        EmployeeUserResolver $accounts,
        SystemSettingsService $settings,
    ): void {
        $announcement = TransferAnnouncement::query()->with('positions.position')->find($this->announcementId);
        if ($announcement === null || ! $announcement->status->canAcceptApplications()) {
            return;
        }
        $channels = [];
        $group = SystemSettingsRegistry::GROUP_NOTIFICATIONS;
        if (filter_var($settings->get($group, 'database_notifications_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            $channels[] = 'database';
        }
        if (filter_var($settings->get($group, 'email_notifications_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $channels[] = 'mail';
        }
        if ($channels === []) {
            return;
        }

        // Read once per run, not once per employee and position.
        $transferSettings = TransferSetting::current();
        $after = $this->afterEmployeeId;

        for ($batch = 0; $batch < $this->batchesPerRun; $batch++) {
            $employees = Employee::query()->where('status', 'active')
                ->when($after !== null, fn ($query) => $query->where('id', '>', $after))
                ->orderBy('id')->limit($this->batchSize)
                ->with('currentAssignment.position')->get();
            if ($employees->isEmpty()) {
                return;
            }

            $users = $accounts->usersFor($employees);
            foreach ($employees as $employee) {
                $user = $users[$employee->id] ?? null;
                if ($user === null || ($channels === ['mail'] && ! $user->email)) {
                    continue;
                }
                $eligible = $announcement->positions->isEmpty()
                    ? $eligibility->evaluate($employee, $announcement, $transferSettings)['eligible']
                    : $announcement->positions->contains(fn ($position): bool => $eligibility->evaluate($employee, $announcement, $transferSettings, $position)['eligible']);
                if ($eligible) {
                    $userChannels = $user->email ? $channels : array_values(array_diff($channels, ['mail']));
                    Notification::send($user, new TransferAnnouncementNotification($announcement->id, $userChannels));
                }
            }

            $after = (string) $employees->last()->id;
            if ($employees->count() < $this->batchSize) {
                return;
            }
        }

        // More employees remain: continue in a fresh run.
        self::dispatch($this->announcementId, $after);
    }
}
