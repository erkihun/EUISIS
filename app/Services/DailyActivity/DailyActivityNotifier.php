<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\DailyActivityNotification;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Collection;
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
        return $this->notifyUser($this->userFor($employee), $employee, $kind, $activityDate, $comment);
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

    /**
     * userFor() for a whole batch in two queries, so a reminder run over
     * thousands of employees does not issue two lookups per person.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<string, User|null> employee id => user
     */
    public function usersFor(Collection $employees): array
    {
        $linked = User::query()->whereIn('employee_id', $employees->pluck('id')->all())->where('status', 'active')
            ->orderBy('id')->get()->unique('employee_id')->keyBy('employee_id');
        $emails = $employees->reject(fn (Employee $employee): bool => $linked->has($employee->id))
            ->pluck('email')->filter()->unique()->values()->all();
        $byEmail = $emails === [] ? collect() : User::query()
            ->whereNull('employee_id')->where('employee_link_locked', false)
            ->whereIn('email', $emails)->where('status', 'active')
            ->orderBy('id')->get()->unique('email')->keyBy('email');

        $users = [];
        foreach ($employees as $employee) {
            $users[$employee->id] = $linked->get($employee->id) ?? (filled($employee->email) ? $byEmail->get($employee->email) : null);
        }

        return $users;
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
