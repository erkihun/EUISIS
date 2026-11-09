<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use App\Models\Employee;
use App\Services\Employees\EmployeeUserResolver;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Modules\Transport\Application\Notifications\TransportNotification;
use Modules\Transport\Domain\Models\TransportRoute;

/**
 * Sends transport notices to the employee's own account through the
 * channels System Settings › Notifications enables (in-app, email). A
 * notice is queued after the surrounding transaction commits, so a
 * rolled-back change never notifies.
 */
class TransportEmployeeNotifier
{
    public function __construct(
        private readonly EmployeeUserResolver $resolver,
        private readonly SystemSettingsService $settings,
    ) {}

    /** @param  array<string, string|null>  $extra */
    public function routeAssigned(Employee $employee, TransportRoute $route, bool $changed = false, array $extra = []): void
    {
        $this->send($employee, $changed ? 'route_changed' : 'route_assigned', $this->routeContext($route) + $extra);
    }

    public function assignmentSuspended(Employee $employee, ?TransportRoute $route, string $reason): void
    {
        $this->send($employee, 'assignment_suspended', ($route ? $this->routeContext($route) : []) + ['note' => $reason]);
    }

    /** @param  array<string, string|null>  $context */
    public function tripCancelled(Employee $employee, TransportRoute $route, array $context): void
    {
        $this->send($employee, 'trip_cancelled', $this->routeContext($route) + $context);
    }

    /** @param  array<string, string|null>  $context */
    public function send(Employee $employee, string $kind, array $context): void
    {
        $channels = $this->channels($employee);
        if ($channels === []) {
            return;
        }

        $user = $this->resolver->userFor($employee);
        if ($user === null) {
            return;
        }

        DB::afterCommit(fn () => $user->notify(new TransportNotification($kind, $context, $channels)));
    }

    /** @return array<string, string|null> */
    private function routeContext(TransportRoute $route): array
    {
        return ['route_code' => $route->route_code, 'route_name_en' => $route->name_en, 'route_name_am' => $route->name_am];
    }

    /** @return list<string> */
    private function channels(Employee $employee): array
    {
        $channels = [];
        if ($this->settings->get(SystemSettingsRegistry::GROUP_NOTIFICATIONS, 'database_notifications_enabled', true)) {
            $channels[] = 'database';
        }
        $preferences = is_array($employee->notification_preferences) ? $employee->notification_preferences : [];
        if ($this->settings->get(SystemSettingsRegistry::GROUP_NOTIFICATIONS, 'email_notifications_enabled', false) && ($preferences['email'] ?? true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
