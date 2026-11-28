<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Backbone\Contracts\AccessScopeReader;
use App\Models\User;
use App\Services\Employees\EmployeeUserResolver;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Modules\Leave\Application\Notifications\LeaveNotification;
use Modules\Leave\Domain\Models\LeavePlan;
use Modules\Leave\Domain\Models\LeaveRequest;
use Throwable;

/** Sends the leave notices. Channels follow the platform's notification settings. */
class LeaveNotifier
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly EmployeeUserResolver $users,
        private readonly AccessScopeReader $holders,
    ) {}

    public function approvalRequired(LeaveRequest $request): void
    {
        if ($request->supervisor_user_id !== null) {
            $this->send(User::query()->whereKey($request->supervisor_user_id)->where('status', 'active')->first(), 'approval_required', $request);
        }
    }

    /** To the HR officers who may confirm leave for the request's institution. */
    public function confirmationRequired(LeaveRequest $request): void
    {
        if ($request->organization_id === null) {
            return;
        }
        $ids = array_map(static fn ($holder): int => $holder->userId, $this->holders->holdersFor('leave.manage', $request->organization_id, 25));
        foreach (User::query()->whereIn('id', $ids)->where('status', 'active')->get() as $user) {
            if ((int) $user->getKey() !== (int) $request->requester_user_id) {
                $this->send($user, 'confirmation_required', $request);
            }
        }
    }

    public function toEmployee(LeaveRequest $request, string $kind): void
    {
        $request->loadMissing('employee');
        if ($request->employee !== null) {
            $this->send($this->users->userFor($request->employee), $kind, $request);
        }
    }

    /** A planned period of the leave schedule was approved or sent back: tell the employee. */
    public function planDecided(LeavePlan $plan, string $kind): void
    {
        $plan->loadMissing('employee');
        if ($plan->employee !== null) {
            $this->planNotice($this->users->userFor($plan->employee), $kind, $plan);
        }
    }

    /** A notice about one planned period; its "reference" is the period itself. */
    public function planNotice(?User $user, string $kind, LeavePlan $plan): void
    {
        $channels = $this->channelsFor($user);
        if ($user === null || $channels === []) {
            return;
        }

        try {
            $user->notify(new LeaveNotification($kind, $plan->id, $plan->starts_on->toDateString().' – '.$plan->ends_on->toDateString(), $channels));
        } catch (Throwable $exception) {
            Log::warning('Leave schedule notification failed', ['leave_plan_id' => $plan->id, 'kind' => $kind, 'exception' => $exception->getMessage()]);
        }
    }

    private function send(?User $user, string $kind, LeaveRequest $request): void
    {
        $channels = $this->channelsFor($user);
        if ($user === null || $channels === []) {
            return;
        }

        try {
            $user->notify(new LeaveNotification($kind, $request->id, $request->reference_number, $channels));
        } catch (Throwable $exception) {
            Log::warning('Leave notification failed', ['leave_request_id' => $request->id, 'kind' => $kind, 'exception' => $exception->getMessage()]);
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
        if (filter_var($this->settings->get($group, 'email_notifications_enabled', false), FILTER_VALIDATE_BOOLEAN) && $user->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
