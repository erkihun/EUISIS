<?php

declare(strict_types=1);

namespace Modules\DailyWork\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeUserResolver;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Modules\DailyWork\Application\Notifications\DailyActivityNotification;
use Throwable;

/**
 * Delivers Daily Activity notices through the existing notification channels.
 *
 * The employee is reached through their user account (EmployeeUserResolver). A delivery failure is logged and swallowed: a mail
 * outage must never roll back an approval or a return for correction.
 */
class DailyActivityNotifier
{
    public function __construct(private readonly SystemSettingsService $settings, private readonly EmployeeUserResolver $users) {}

    public function notifyEmployee(Employee $employee, string $kind, string $activityDate, ?string $comment = null): bool
    {
        return $this->notifyUser($this->users->userFor($employee), $employee, $kind, $activityDate, $comment);
    }

    /** As notifyEmployee(), for a user already resolved (see usersFor()). */
    public function notifyUser(?User $user, Employee $employee, string $kind, string $activityDate, ?string $comment = null): bool
    {
        $channels = $this->channelsFor($user);

        if ($user === null || $channels === []) {
            return false;
        }

        try {
            $user->notify(new DailyActivityNotification($kind, $activityDate, $comment, $channels));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Daily activity notification failed', [
                'employee_id' => $employee->id,
                'kind' => $kind,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** @return array<int, string> */
    private function channelsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $group = SystemSettingsRegistry::GROUP_NOTIFICATIONS;
        $channels = [];

        if (filter_var($this->settings->get($group, 'database_notifications_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            $channels[] = 'database';
        }

        if (filter_var($this->settings->get($group, 'email_notifications_enabled', false), FILTER_VALIDATE_BOOLEAN)
            && $user->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
