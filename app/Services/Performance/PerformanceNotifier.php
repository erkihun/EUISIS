<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\EmployeePerformanceAgreement;
use App\Models\User;
use App\Notifications\PerformanceNotification;
use App\Services\DailyActivity\DailyActivityNotifier;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends EPMS notifications through the existing channels and on/off settings. */
final class PerformanceNotifier
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly DailyActivityNotifier $accounts,
        private readonly EpmsSettings $epms,
    ) {}

    public function toEmployee(?Employee $employee, string $kind, ?EmployeePerformanceAgreement $agreement = null): void
    {
        if ($employee === null) {
            return;
        }

        $this->toUser($this->accounts->userFor($employee), $kind, '/my-portal/performance');
    }

    public function toUser(?User $user, string $kind, ?string $url = null): void
    {
        if ($user === null || ! $this->epms->enabled()) {
            return;
        }

        $channels = [];
        $group = SystemSettingsRegistry::GROUP_NOTIFICATIONS;
        if (filter_var($this->settings->get($group, 'database_notifications_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            $channels[] = 'database';
        }
        if (filter_var($this->settings->get($group, 'email_notifications_enabled', false), FILTER_VALIDATE_BOOLEAN) && $user->email) {
            $channels[] = 'mail';
        }
        if ($channels === []) {
            return;
        }

        try {
            $user->notify(new PerformanceNotification($kind, $url, $channels));
        } catch (Throwable $exception) {
            Log::warning('Performance notification failed', ['kind' => $kind, 'exception' => $exception->getMessage()]);
        }
    }
}
