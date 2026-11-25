<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Ports\EventOutbox;
use Modules\Reporting\Application\Ports\NotificationPublisher;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Ports\WorkingCalendar;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Contracts\Events\ReportingDeadlineMissedV1;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Domain\Reminders\ReminderStages;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;

/**
 * The time-driven part of the module. Every task here is safe to run again:
 * a deadline is stamped missed once, a reminder stage is delivered to a
 * person once (the delivery key), and each task looks at the state as it is
 * now, so a run that was skipped is made up by the next one.
 *
 * Three reminder tracks, each stopping by itself when its condition is
 * resolved because the query no longer finds the obligation:
 *   submission: required, not yet submitted;
 *   revision: returned and not yet resubmitted;
 *   review: an open review step.
 */
final readonly class ScheduledWork
{
    public function __construct(
        private WorkingCalendar $calendar,
        private ReportingAccessResolver $access,
        private NotificationPublisher $notifications,
        private EventOutbox $outbox,
        private ReportingSettings $settings,
    ) {}

    /** Stamps obligations whose effective deadline has passed with no valid submission, and publishes the event once. */
    public function markMissedDeadlines(?DateTimeImmutable $now = null): int
    {
        $now = $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($now)->utc();
        $marked = 0;
        Obligation::query()->where('applicability', 'required')->whereNull('first_submitted_at')->whereNull('deadline_missed_at')
            ->where('effective_due_at', '<', $now)->orderBy('id')->select('id')
            ->chunkById(500, function ($chunk) use ($now, &$marked): void {
                foreach ($chunk as $row) {
                    $marked += (int) DB::transaction(function () use ($row, $now): bool {
                        // Re-read under a lock: a submission or an extension may have arrived meanwhile.
                        $obligation = Obligation::query()->lockForUpdate()->find($row->id);
                        if ($obligation === null || $obligation->first_submitted_at !== null || $obligation->deadline_missed_at !== null
                            || $obligation->applicability->value !== 'required' || $obligation->effective_due_at >= $now) {
                            return false;
                        }
                        $obligation->forceFill(['deadline_missed_at' => $now])->save();
                        $this->outbox->store(new ReportingDeadlineMissedV1(
                            eventId: Str::uuid7()->toString(), occurredAt: $now->toIso8601String(), obligationId: $obligation->id,
                            organizationId: $obligation->organization_id, reportTypeId: $obligation->report_type_id, cycleId: $obligation->cycle_id,
                            effectiveDueAt: $obligation->effective_due_at->toIso8601String(),
                        ));

                        return true;
                    });
                }
            });

        return $marked;
    }

    /** @return array{submission: int, revision: int, review: int} notices newly handed over, per track */
    public function sendReminders(?DateTimeImmutable $now = null): array
    {
        $now = $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($now)->utc();
        $defaults = (array) ($this->settings->get('reminder_rules') ?? ReminderStages::DEFAULT_RULES);
        $sent = ['submission' => 0, 'revision' => 0, 'review' => 0];
        /** @var array<string, Cycle> $cycles */
        $cycles = [];
        /** @var array<string, ReportType> $types */
        $types = [];
        $context = function (Obligation $o) use (&$cycles, &$types): array {
            $cycle = $cycles[$o->cycle_id] ??= Cycle::query()->findOrFail($o->cycle_id);
            $type = $types[$o->report_type_id] ??= ReportType::query()->findOrFail($o->report_type_id);

            return [$cycle, [
                'institution' => (string) $o->organization_name_en, 'institution_am' => (string) $o->organization_name_am,
                'period' => $cycle->period_label_en, 'period_am' => $cycle->period_label_am, 'type' => $type->name_en, 'type_am' => $type->name_am,
            ]];
        };
        $local = fn (CarbonImmutable $at): string => $at->setTimezone('Africa/Addis_Ababa')->format('Y-m-d H:i').' EAT';

        // Submission track.
        Obligation::query()->where('applicability', 'required')->whereNull('first_submitted_at')->where('opens_at', '<=', $now)
            ->where('effective_due_at', '<=', $now->addDays(31))->chunkById(500, function ($chunk) use ($now, $defaults, $context, $local, &$sent): void {
                foreach ($chunk as $o) {
                    [$cycle, $params] = $context($o);
                    $rules = $cycle->reminder_rules ?: $defaults;
                    $stage = $this->stage($rules, $o->effective_due_at, $now);
                    if ($stage === null) {
                        continue;
                    }
                    // The deadline is part of the key: after an extension the stages run again for the new date.
                    $sent['submission'] += $this->notifications->publish('submission', $stage, "submission:{$o->id}:{$o->effective_due_at->getTimestamp()}:{$stage}",
                        $this->reportingSide($o, $stage === 'escalated'), [...$params, 'due' => $local($o->effective_due_at)], $o->id);
                }
            });

        // Revision track: its own deadline, independent of the original one.
        Obligation::query()->where('workflow_status', WorkflowStatus::Returned->value)->whereNotNull('revision_due_at')
            ->chunkById(500, function ($chunk) use ($now, $defaults, $context, $local, &$sent): void {
                foreach ($chunk as $o) {
                    [$cycle, $params] = $context($o);
                    $stage = $this->stage($cycle->reminder_rules ?: $defaults, $o->revision_due_at, $now);
                    if ($stage === null) {
                        continue;
                    }
                    $sent['revision'] += $this->notifications->publish('revision', $stage, "revision:{$o->id}:v{$o->current_version}:{$stage}",
                        $this->reportingSide($o, $stage === 'escalated'), [...$params, 'due' => $local($o->revision_due_at)], $o->id);
                }
            });

        // Reviewer track: the open step of each submission under review.
        ReviewStep::query()->where('status', 'pending')->whereNotNull('due_at')->chunkById(500, function ($chunk) use ($now, $defaults, $context, $local, &$sent): void {
            foreach ($chunk as $step) {
                $o = Obligation::query()->find($step->obligation_id);
                if ($o === null || ! $o->workflow_status->awaitsDecision() || $o->latest_submission_id !== $step->submission_id) {
                    continue;
                }
                [$cycle, $params] = $context($o);
                $stage = $this->stage($cycle->reminder_rules ?: $defaults, $step->due_at, $now);
                if ($stage === null) {
                    continue;
                }
                $recipients = $step->reviewer_user_id !== null ? [(int) $step->reviewer_user_id]
                    : ($o->receiving_organization_id === null ? [] : array_map(fn ($u): int => $u->id, $this->access->receiversFor($step->required_permission, $o->receiving_organization_id, 10)));
                if ($stage === 'escalated' && $o->receiving_organization_id !== null) {
                    // An overdue review goes up to the people who approve at the receiving institution.
                    $recipients = [...$recipients, ...array_map(fn ($u): int => $u->id, $this->access->receiversFor(ReportingRoles::APPROVE, $o->receiving_organization_id, 5))];
                }
                $sent['review'] += $this->notifications->publish('review', $stage, "review:{$step->id}:{$stage}", $recipients, [...$params, 'due' => $local($step->due_at)], $o->id);
            }
        });

        return $sent;
    }

    /** @param array<string, mixed> $rules */
    private function stage(array $rules, CarbonImmutable $dueAt, CarbonImmutable $now): ?string
    {
        $after = $rules['escalate_after_working_days'] ?? ReminderStages::DEFAULT_RULES['escalate_after_working_days'];
        $escalateAt = $after === null || $now <= $dueAt ? null : $this->calendar->addWorkingDays($dueAt->toDateTimeImmutable(), (int) $after);

        return ReminderStages::current($rules, $dueAt->toDateTimeImmutable(), $now->toDateTimeImmutable(), $escalateAt);
    }

    /**
     * The preparer and the accountable officer; when neither is named, the
     * institution's submitters. An escalation always includes the accountable
     * officer and the submitters.
     *
     * @return list<int>
     */
    private function reportingSide(Obligation $o, bool $escalated): array
    {
        $named = array_values(array_filter([(int) $o->person_user_id, (int) $o->preparer_user_id, (int) $o->accountable_user_id]));
        if ($named !== [] && ! $escalated) {
            return $named;
        }

        return [...$named, ...array_map(fn ($u): int => $u->id, $this->access->holdersFor(ReportingRoles::SUBMIT, $o->organization_id, 10))];
    }
}
