<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Contracts\SmsGateway;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\User;
use App\Notifications\GrievanceNotification;
use App\Services\DailyActivity\DailyActivityNotifier;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends grievance notifications through the platform channels and switches.
 * Messages carry the case number and the kind of action only; SMS is a short
 * "action required" notice, never grievance content. Sending happens after
 * the surrounding transaction commits, so a rolled-back action notifies nobody.
 */
final class GrievanceNotifier
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly GrievanceSettings $grievanceSettings,
        private readonly DailyActivityNotifier $accounts,
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly SmsGateway $sms,
    ) {}

    public function toComplainant(Grievance $grievance, string $kind): void
    {
        $user = $grievance->submitted_by_user_id !== null ? User::query()->find($grievance->submitted_by_user_id) : null;
        if ($user === null && $grievance->employee instanceof Employee) {
            $user = $this->accounts->userFor($grievance->employee);
        }

        $this->send($user, $kind, $grievance, '/my-portal/grievances/'.$grievance->getKey(), smsPhone: $grievance->employee?->phone);
    }

    /** Everyone currently handling the stage (panel members, case officers, or the unit's assigning officers). */
    public function toStageHandlers(GrievanceCaseStage $stage, string $kind, ?User $except = null): void
    {
        $grievance = $stage->grievance;
        foreach ($this->stageHandlerUsers($stage) as $user) {
            if ($except !== null && $user->is($except)) {
                continue;
            }
            $this->send($user, $kind, $grievance, '/grievances/cases/'.$grievance->getKey());
        }
    }

    /** @param  iterable<User>  $users */
    public function toUsers(iterable $users, string $kind, Grievance $grievance, ?string $url = null): void
    {
        foreach ($users as $user) {
            $this->send($user, $kind, $grievance, $url ?? '/grievances/cases/'.$grievance->getKey());
        }
    }

    /** @return list<User> */
    public function stageHandlerUsers(GrievanceCaseStage $stage): array
    {
        $users = [];
        if ($stage->handler_type === GrievanceHandlerType::Committee) {
            $employeeIds = $stage->activeMembers()->whereNull('recused_at')->pluck('employee_id')->all();
            $users = User::query()->whereIn('employee_id', $employeeIds)->where('status', 'active')->get()->all();
        } elseif ($stage->handler_type === GrievanceHandlerType::OrganizationUnit) {
            $officerIds = $stage->activeOfficers()->pluck('user_id')->all();
            $users = $officerIds !== []
                ? User::query()->whereIn('id', $officerIds)->where('status', 'active')->get()->all()
                : $this->handlers->unitUsersWithPermission((string) $stage->handler_id, 'grievances.assign');
        } elseif ($stage->handler_type === GrievanceHandlerType::ExternalAuthority) {
            $users = User::permission('grievances.tribunal')->where('status', 'active')->get()->all();
        }

        return array_values(array_filter($users, fn (User $u) => $u->can('grievances.view_assigned') || $u->can('grievances.tribunal')));
    }

    private function send(?User $user, string $kind, Grievance $grievance, string $url, ?string $smsPhone = null): void
    {
        if ($user === null || ! $this->grievanceSettings->enabled()) {
            return;
        }

        $caseNumber = (string) $grievance->reference_number;
        DB::afterCommit(function () use ($user, $kind, $caseNumber, $url, $smsPhone): void {
            $channels = $this->channels($user);
            try {
                if ($channels !== []) {
                    $user->notify(new GrievanceNotification($kind, $caseNumber, $url, $channels));
                }
                if ($smsPhone && $this->grievanceSettings->smsNoticesEnabled() && $this->sms->isConfigured()) {
                    // Deliberately generic: a case number and a pointer to the portal.
                    $this->sms->send($smsPhone, (string) __('grievances.notifications.sms', ['case' => $caseNumber]));
                }
            } catch (Throwable $exception) {
                Log::warning('Grievance notification failed', ['kind' => $kind, 'exception' => $exception->getMessage()]);
            }
        });
    }

    /** @return list<string> */
    private function channels(User $user): array
    {
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
