<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\DailyActivityNotification;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers Daily Activity notices through the existing notification channels.
 *
 * The employee is reached through their user account (the same email join
 * User::employee() uses). A delivery failure is logged and swallowed: a mail
 * outage must never roll back an approval or a return for correction.
 */
class DailyActivityNotifier
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function notifyEmployee(Employee $employee, string $kind, string $activityDate, ?string $comment = null): bool
    {
        $user = $this->userFor($employee);
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

    public function userFor(Employee $employee): ?User
    {
        $linked = User::query()->where('employee_id', $employee->id)->where('status', 'active')->first();
        if ($linked) {
            return $linked;
        }
        if ($employee->email === null || $employee->email === '') {
            return null;
        }

        return User::query()
            ->whereNull('employee_id')->where('employee_link_locked', false)
            ->where('email', $employee->email)
            ->where('status', 'active')
            ->first();
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
