<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Application\Services;

use App\Backbone\Contracts\AccessScopeReader;
use App\Models\User;
use App\Services\Employees\EmployeeUserResolver;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Modules\WorkExperience\Application\Notifications\WorkExperienceNotification;
use Modules\WorkExperience\Domain\Models\WorkExperienceRequest;
use Throwable;

/** Sends the work experience notices. Channels follow the platform's notification settings. */
class WorkExperienceNotifier
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly EmployeeUserResolver $users,
        private readonly AccessScopeReader $holders,
    ) {}

    /** To the officers who may decide requests for the request's institution. */
    public function toOffice(WorkExperienceRequest $request): void
    {
        if ($request->organization_id === null) {
            return;
        }
        $ids = array_map(static fn ($holder): int => $holder->userId, $this->holders->holdersFor('work_experience.requests.manage', $request->organization_id, 25));
        foreach (User::query()->whereIn('id', $ids)->where('status', 'active')->get() as $user) {
            if ((int) $user->getKey() !== (int) $request->requester_user_id) {
                $this->send($user, 'submitted', $request);
            }
        }
    }

    /** To the employee, when they have an account; one who has left may have none. */
    public function toEmployee(WorkExperienceRequest $request, string $kind): void
    {
        $request->loadMissing('employee');
        if ($request->employee !== null) {
            $this->send($this->users->userFor($request->employee), $kind, $request);
        }
    }

    private function send(?User $user, string $kind, WorkExperienceRequest $request): void
    {
        $channels = $this->channelsFor($user);
        if ($user === null || $channels === []) {
            return;
        }

        try {
            $user->notify(new WorkExperienceNotification($kind, $request->id, $request->reference_number, $channels));
        } catch (Throwable $exception) {
            Log::warning('Work experience notification failed', ['work_experience_request_id' => $request->id, 'kind' => $kind, 'exception' => $exception->getMessage()]);
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
