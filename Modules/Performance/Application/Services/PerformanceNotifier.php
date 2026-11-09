<?php

declare(strict_types=1);

namespace Modules\Performance\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeUserResolver;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Modules\Performance\Application\Notifications\PerformanceNotification;
use Modules\Performance\Domain\Models\EmployeePerformanceAgreement;
use Throwable;

/** Sends EPMS notifications through the existing channels and on/off settings. */
final class PerformanceNotifier
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly EmployeeUserResolver $accounts,
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
