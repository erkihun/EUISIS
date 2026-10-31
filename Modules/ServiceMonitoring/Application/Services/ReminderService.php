<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Contracts\SmsGateway;
use App\Enums\OrganizationScopeType;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\ServiceMonitoring\Application\Notifications\ActionItemOverdueNotification;
use Modules\ServiceMonitoring\Application\Notifications\ClarificationRepliedNotification;
use Modules\ServiceMonitoring\Application\Notifications\DigitalServiceRejectedNotification;
use Modules\ServiceMonitoring\Application\Notifications\DueInThreeDaysNotification;
use Modules\ServiceMonitoring\Application\Notifications\DueTodayNotification;
use Modules\ServiceMonitoring\Application\Notifications\MissingAfterDueNotification;
use Modules\ServiceMonitoring\Application\Notifications\PeriodOpenedNotification;
use Modules\ServiceMonitoring\Application\Notifications\ServiceMonitoringNotification;
use Modules\ServiceMonitoring\Application\Notifications\SubmissionReturnedNotification;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringRoles;
use Modules\ServiceMonitoring\Domain\Enums\FiscalYearStatus;
use Modules\ServiceMonitoring\Domain\Enums\PeriodStatus;
use Modules\ServiceMonitoring\Domain\Enums\ReminderType;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReminderLog;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Throwable;

/**
 * Reminders and notices (D23). The daily job calls run(); returns, verification
 * rejections and clarification replies are sent at once. Each reminder is
 * logged under (participant, subject, type, day), so re-running the job on the
 * same day sends nothing twice. In-app and email follow the platform's
 * notification settings; SMS goes through SmsGateway only when it is
 * configured and the user has a phone number. A delivery failure never
 * undoes the action that triggered it.
 *
 *   period_opened        on the period's opens_on day
 *   due_in_3_days        three days before the deadline, to those not yet submitted
 *   due_today            on the deadline day, to those not yet submitted
 *   missing_after_due    the day after the deadline, to the missing participant's
 *                        submitters and to its reviewers
 *   action_item_overdue  the day after an action item's due date, then weekly
 */
class ReminderService
{
    public function __construct(
        private readonly ParticipantResolver $participants,
        private readonly ComplianceService $compliance,
        private readonly FiscalCalendar $calendar,
        private readonly SystemSettingsService $settings,
        private readonly SmsGateway $sms,
    ) {}

    /** @return array<string, int> reminders sent per type */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $this->calendar->today($now);
        $sent = array_fill_keys(ReminderType::values(), 0);

        $periods = ReportingPeriod::query()->with('fiscalYear')
            ->whereHas('fiscalYear', fn (Builder $q): Builder => $q->where('status', FiscalYearStatus::Active->value))
            ->where('status', '!=', PeriodStatus::Locked->value)->get();

        foreach ($periods as $period) {
            if ($period->status !== $period->effectiveStatus($now)) {
                $period->forceFill(['status' => $period->effectiveStatus($now)])->save();
            }
            $due = $period->submission_due_on->toDateString();
            $type = match ($today) {
                $period->opens_on->toDateString() => ReminderType::PeriodOpened,
                $period->submission_due_on->subDays(3)->toDateString() => ReminderType::DueInThreeDays,
                $due => ReminderType::DueToday,
                $period->submission_due_on->addDay()->toDateString() => ReminderType::MissingAfterDue,
                default => null,
            };
            if ($type === null) {
                continue;
            }
            $expected = $this->participants->expectedFor($period)->with('organization:id,name_en,name_am', 'parent.organization:id')->get();
            $status = $this->compliance->forPeriod($period, $expected, $now);
            foreach ($expected as $participant) {
                $wanted = match ($type) {
                    ReminderType::PeriodOpened => true,
                    ReminderType::MissingAfterDue => $status[$participant->id]['status'] === 'missing',
                    default => in_array($status[$participant->id]['status'], ['pending', 'returned'], true),
                };
                $log = $wanted ? $this->claim($participant, $period->id, $type, $period, $today) : null;
                if ($log !== null) {
                    $recipients = $this->submitters($participant);
                    if ($type === ReminderType::MissingAfterDue) {
                        $recipients = $recipients->merge($this->reviewers($participant))->unique('id');
                    }
                    $this->deliver($recipients, $this->notification($type, $participant, $period), $type, $log);
                    $sent[$type->value]++;
                }
            }
        }

        foreach (ActionItem::query()->overdue($today)->with('visit.participant.organization', 'visit.members', 'ownerUser')->get() as $item) {
            $daysLate = (int) $item->due_on->diffInDays(CarbonImmutable::parse($today), true);
            $participant = $item->visit?->participant;
            $log = $participant !== null && $daysLate % 7 === 1 ? $this->claim($participant, $item->id, ReminderType::ActionItemOverdue, null, $today) : null;
            if ($log === null) {
                continue;
            }
            $recipients = collect([$item->ownerUser])->filter()->merge($item->visit->members)->merge($this->submittersOf($item->owner_organization_id))->unique('id');
            $notification = new ActionItemOverdueNotification(
                ['title' => $item->title, 'organization' => $this->name($participant), 'due' => $this->calendar->display($item->due_on->toDateString())],
                'service-monitoring.action-items.index', [], [],
            );
            $this->deliver($recipients, $notification, ReminderType::ActionItemOverdue, $log);
            $sent[ReminderType::ActionItemOverdue->value]++;
        }

        return $sent;
    }

    public function notifyReturned(Submission $submission, string $reason): void
    {
        $participant = $submission->participant;
        $period = $submission->period;
        // Every return is notified; the log keeps one row per submission and day.
        $log = $this->claim($participant, $submission->id, ReminderType::Returned, $period, $this->calendar->today());
        $notification = new SubmissionReturnedNotification(
            ['organization' => $this->name($participant), 'period' => $period->label(), 'reason' => mb_substr($reason, 0, 300)],
            'service-monitoring.submissions.edit', ['submission' => $submission->id], [],
        );
        $this->deliver($this->submitters($participant), $notification, ReminderType::Returned, $log);
    }

    public function notifyDigitalServiceRejected(DigitalService $service): void
    {
        $submission = $service->submission;
        $notification = new DigitalServiceRejectedNotification(
            ['service' => (string) ($service->service_name_am ?: $service->service_name_en), 'organization' => $this->name($submission->participant),
                'period' => $submission->period->label(), 'reason' => (string) $service->rejection_reason],
            'service-monitoring.submissions.show', ['submission' => $submission->id], [],
        );
        $this->deliver($this->submitters($submission->participant), $notification, null, null);
    }

    public function notifyClarificationReplied(Clarification $clarification): void
    {
        $opener = $clarification->opener;
        if ($opener === null) {
            return;
        }
        $notification = new ClarificationRepliedNotification(['subject' => $clarification->subject], 'service-monitoring.clarifications.show', ['clarification' => $clarification->id], []);
        $this->deliver(collect([$opener]), $notification, null, null);
    }

    /**
     * The users who prepare a participant's report: holders of the submit
     * permission explicitly assigned to its organization, or placed there.
     *
     * @return Collection<int, User>
     */
    public function submitters(Participant $participant): Collection
    {
        return $this->submittersOf($participant->organization_id);
    }

    /** @return Collection<int, User> */
    private function submittersOf(string $organizationId): Collection
    {
        return User::query()->where('status', 'active')
            ->where(fn (Builder $q): Builder => $q
                ->whereHas('organizationScopes', fn (Builder $s): Builder => $s->active()->where('organization_id', $organizationId))
                ->orWhereHas('employee.currentAssignment', fn (Builder $a): Builder => $a->where('organization_id', $organizationId)))
            ->get()
            ->filter(fn (User $u): bool => $u->can('service_monitoring.submit'))
            ->values();
    }

    /**
     * Reviewers of a participant: for a woreda, review holders assigned to the
     * woreda or its sub-city; otherwise the city coordinators.
     *
     * @return Collection<int, User>
     */
    public function reviewers(Participant $participant): Collection
    {
        $query = User::query()->where('status', 'active');
        if ($participant->level === ReportingLevel::Woreda) {
            $organizations = array_filter([$participant->organization_id, $participant->parent?->organization_id]);
            $query->whereHas('organizationScopes', fn (Builder $s): Builder => $s->active()->whereIn('organization_id', $organizations));
        } else {
            $query->where(fn (Builder $q): Builder => $q
                ->whereHas('organizationScopes', fn (Builder $s): Builder => $s->active()->where('scope_type', OrganizationScopeType::Citywide->value))
                ->orWhereHas('roles', fn (Builder $r): Builder => $r->where('name', ServiceMonitoringRoles::COORDINATOR_ROLE)));
        }

        return $query->get()->filter(fn (User $u): bool => $u->can('service_monitoring.review'))->values();
    }

    private function notification(ReminderType $type, Participant $participant, ReportingPeriod $period): ServiceMonitoringNotification
    {
        $params = ['organization' => $this->name($participant), 'period' => $period->label(), 'due' => $this->calendar->display($period->submission_due_on->toDateString())];

        return match ($type) {
            ReminderType::PeriodOpened => new PeriodOpenedNotification($params, 'service-monitoring.dashboard', [], []),
            ReminderType::DueInThreeDays => new DueInThreeDaysNotification($params, 'service-monitoring.dashboard', [], []),
            ReminderType::DueToday => new DueTodayNotification($params, 'service-monitoring.dashboard', [], []),
            default => new MissingAfterDueNotification($params, 'service-monitoring.dashboard', [], []),
        };
    }

    /**
     * Takes the (participant, subject, type, day) slot; null when it was
     * already taken. Checked first rather than caught, so a duplicate never
     * aborts a surrounding PostgreSQL transaction; the unique index stays the
     * last line of defence.
     */
    private function claim(Participant $participant, string $subjectKey, ReminderType $type, ?ReportingPeriod $period, string $today): ?ReminderLog
    {
        $taken = ReminderLog::query()->where('participant_id', $participant->id)->where('subject_key', $subjectKey)
            ->where('type', $type->value)->where('sent_on', $today)->exists();

        return $taken ? null : ReminderLog::query()->create([
            'participant_id' => $participant->id, 'period_id' => $period?->id, 'type' => $type, 'sent_on' => $today, 'subject_key' => $subjectKey,
        ]);
    }

    /** @param Collection<int, User> $recipients */
    private function deliver(Collection $recipients, ServiceMonitoringNotification $template, ?ReminderType $type, ?ReminderLog $log): void
    {
        $group = SystemSettingsRegistry::GROUP_NOTIFICATIONS;
        $database = filter_var($this->settings->get($group, 'database_notifications_enabled', true), FILTER_VALIDATE_BOOLEAN);
        $mail = filter_var($this->settings->get($group, 'email_notifications_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $smsKey = $type !== null && in_array($type, [ReminderType::DueToday, ReminderType::MissingAfterDue, ReminderType::Returned], true) ? $type->value : null;
        $smsReady = $smsKey !== null && $this->sms->isConfigured();

        foreach ($recipients as $user) {
            $channels = array_values(array_filter([$database ? 'database' : null, $mail && $user->email ? 'mail' : null]));
            try {
                if ($channels !== []) {
                    $user->notify(new ($template::class)($template->params, $template->routeName, $template->routeParameters, $channels));
                }
                if ($smsReady && filled($user->phone_number)) {
                    $this->sms->send((string) $user->phone_number, (string) __("service-monitoring.sms.{$smsKey}", $template->params));
                }
            } catch (Throwable $exception) {
                Log::warning('Service monitoring notice could not be delivered.', ['user_id' => $user->id, 'kind' => $template->kind(), 'error' => $exception->getMessage()]);
            }
        }
        $log?->forceFill(['recipients' => $recipients->count()])->save();
    }

    private function name(Participant $participant): string
    {
        $organization = $participant->organization;

        return (string) (app()->getLocale() === 'am' && $organization?->name_am ? $organization->name_am : $organization?->name_en);
    }
}
