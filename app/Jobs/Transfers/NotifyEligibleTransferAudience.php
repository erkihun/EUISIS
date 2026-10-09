<?php

declare(strict_types=1);

namespace App\Jobs\Transfers;

use App\Models\Employee;
use App\Models\TransferAnnouncement;
use App\Notifications\TransferAnnouncementNotification;
use App\Services\DailyActivity\DailyActivityNotifier;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/** Queued and chunked: publishing must not enumerate the workforce in HTTP. */
class NotifyEligibleTransferAudience implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $announcementId) {}

    public function handle(
        TransferEligibilityService $eligibility,
        DailyActivityNotifier $accounts,
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

        Employee::query()->where('status', 'active')->with('currentAssignment.position')->chunkById(200,
            function ($employees) use ($announcement, $eligibility, $accounts, $channels): void {
                $users = $accounts->usersFor($employees);
                foreach ($employees as $employee) {
                    $user = $users[$employee->id] ?? null;
                    if ($user === null || ($channels === ['mail'] && ! $user->email)) {
                        continue;
                    }
                    $eligible = $announcement->positions->isEmpty()
                        ? $eligibility->evaluate($employee, $announcement)['eligible']
                        : $announcement->positions->contains(fn ($position): bool => $eligibility->evaluate($employee, $announcement, announcementPosition: $position)['eligible']);
                    if ($eligible) {
                        $userChannels = $user->email ? $channels : array_values(array_diff($channels, ['mail']));
                        Notification::send($user, new TransferAnnouncementNotification($announcement->id, $userChannels));
                    }
                }
            }, 'id');
    }
}
