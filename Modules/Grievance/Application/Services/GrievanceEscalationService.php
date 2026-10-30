<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceMovementType;
use App\Enums\Grievance\GrievanceSlaPauseStatus;
use App\Enums\Grievance\GrievanceStageStatus;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Timeout, manual escalation, reassignment and referral
 * (docs/grievance-management.md §6.4).
 *
 * Automatic escalation is driven by the scheduler (grievances:process-sla),
 * never by page visits, and is idempotent three ways: the grievance row is
 * locked, every precondition is re-checked under the lock, and a stage can
 * have only one successor (unique from_stage_id) — so a second run, or a
 * second server, cannot escalate the same stage twice.
 */
final class GrievanceEscalationService
{
    /** A stage in executive approval is waiting on the approver, not the handler: never timed out. */
    private const NOT_ESCALATED_WHILE = [GrievanceStageStatus::PendingApproval];

    public function __construct(
        private readonly GrievanceRoutingService $routing,
        private readonly GrievanceSlaService $sla,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceNotifier $notifier,
        private readonly GrievanceCaseAccessService $access,
    ) {}

    /**
     * Scheduled sweep: warnings for running stages, then escalation of
     * overdue ones.
     *
     * @return array{warned: int, escalated: int, blocked: int}
     */
    public function processDueStages(): array
    {
        $stats = ['warned' => 0, 'escalated' => 0, 'blocked' => 0];

        GrievanceCaseStage::query()->open()->whereNotNull('due_at')
            ->whereDoesntHave('pauses', fn ($q) => $q->where('status', GrievanceSlaPauseStatus::Active->value))
            ->with('grievance')
            ->orderBy('due_at')
            ->chunkById(200, function ($stages) use (&$stats): void {
                foreach ($stages as $stage) {
                    try {
                        $stats['warned'] += $this->sendWarnings($stage);

                        if ($stage->auto_escalate && $this->sla->isOverdue($stage) && ! in_array($stage->status, self::NOT_ESCALATED_WHILE, true)) {
                            $result = $this->autoEscalate($stage->getKey());
                            $result === 'escalated' ? $stats['escalated']++ : ($result === 'blocked' ? $stats['blocked']++ : null);
                        }
                    } catch (Throwable $exception) {
                        // One bad case must not stop the sweep; it is retried next run.
                        Log::error('Grievance SLA processing failed', ['stage' => $stage->getKey(), 'exception' => $exception->getMessage()]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Escalate one overdue stage along its TIMEOUT_ESCALATION route.
     *
     * @return 'escalated'|'blocked'|'skipped'
     */
    public function autoEscalate(string $stageId): string
    {
        try {
            return DB::transaction(function () use ($stageId): string {
                $stage = GrievanceCaseStage::query()->find($stageId);
                if ($stage === null) {
                    return 'skipped';
                }
                $grievance = Grievance::query()->whereKey($stage->grievance_id)->lockForUpdate()->first();
                $stage = GrievanceCaseStage::query()->whereKey($stageId)->lockForUpdate()->first();

                // Re-check everything under the lock.
                if ($grievance === null || $stage === null || ! $stage->isOpen() || ! $stage->auto_escalate
                    || $grievance->current_stage_id !== $stage->getKey() || $grievance->isFinal()
                    || ! $this->sla->isOverdue($stage) || $stage->isPaused()
                    || in_array($stage->status, self::NOT_ESCALATED_WHILE, true)
                    || GrievanceCaseStage::query()->where('from_stage_id', $stage->getKey())->exists()) {
                    return 'skipped';
                }

                $options = $this->routing->nextHandlers($stage, GrievanceMovementType::TimeoutEscalation, $grievance->category_id);
                if ($options === []) {
                    $this->recordBlocked($grievance, $stage);

                    return 'blocked';
                }

                $next = $options[0];
                $this->routing->createStage($grievance, $next['type'], $next['id'], GrievanceMovementType::TimeoutEscalation, $next['route'], $stage, 'sla_breach', null);
                $this->audit->record(AuditEventType::GrievanceAutoEscalated, null, $grievance, [
                    'from_stage_id' => $stage->getKey(),
                    'due_at' => $stage->due_at?->toIso8601String(),
                    'target_type' => $next['type']->value,
                    'target_id' => $next['id'],
                ]);

                return 'escalated';
            });
        } catch (QueryException $exception) {
            // Lost a race on the unique successor: another run escalated it.
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                return 'skipped';
            }
            throw $exception;
        }
    }

    /**
     * Handler-initiated escalation along a configured route (reason required).
     */
    public function manualEscalate(Grievance $grievance, User $actor, string $reason, ?string $routeId): GrievanceCaseStage
    {
        $this->access->authorize($this->access->canHandle($actor, $grievance, 'grievances.escalate'));

        return $this->move($grievance, $actor, GrievanceMovementType::ManualEscalation, $reason, $routeId, AuditEventType::GrievanceManuallyEscalated);
    }

    /** Move to another handler through a configured REASSIGNED route. */
    public function reassign(Grievance $grievance, User $actor, string $reasonCode, ?string $notes, ?string $routeId): GrievanceCaseStage
    {
        $this->access->authorize($this->access->canHandle($actor, $grievance, 'grievances.reassign'));

        return $this->move($grievance, $actor, GrievanceMovementType::Reassigned, trim($reasonCode.($notes ? ': '.$notes : '')), $routeId, AuditEventType::GrievanceReassigned);
    }

    /** Refer along a configured REFERRED route (e.g. to an external authority). */
    public function refer(Grievance $grievance, User $actor, string $reason, ?string $routeId): GrievanceCaseStage
    {
        $this->access->authorize($this->access->canLead($actor, $grievance, 'grievances.escalate'));

        return $this->move($grievance, $actor, GrievanceMovementType::Referred, $reason, $routeId, AuditEventType::GrievanceReferred);
    }

    /** Return to a lower level along a configured RETURNED route (e.g. for rehearing). */
    public function returnToHandler(Grievance $grievance, User $actor, string $reason, ?string $routeId): GrievanceCaseStage
    {
        $this->access->authorize($this->access->canLead($actor, $grievance, 'grievances.escalate'));

        return $this->move($grievance, $actor, GrievanceMovementType::Returned, $reason, $routeId, AuditEventType::GrievanceReassigned);
    }

    private function move(Grievance $grievance, User $actor, GrievanceMovementType $movement, string $reason, ?string $routeId, AuditEventType $event): GrievanceCaseStage
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('grievances.errors.reason_required')]);
        }

        return DB::transaction(function () use ($grievance, $actor, $movement, $reason, $routeId, $event): GrievanceCaseStage {
            $grievance = Grievance::query()->whereKey($grievance->getKey())->lockForUpdate()->firstOrFail();
            $stage = GrievanceCaseStage::query()->whereKey($grievance->current_stage_id)->lockForUpdate()->first();
            if ($stage === null || ! $stage->isOpen() || $grievance->isFinal()) {
                throw ValidationException::withMessages(['grievance' => __('grievances.errors.stale')]);
            }

            $choice = $this->routing->validateChoice($stage, $movement, $grievance->category_id, $routeId);
            $new = $this->routing->createStage($grievance, $choice['type'], $choice['id'], $movement, $choice['route'], $stage, $reason, $actor);
            $this->audit->record($event, $actor, $grievance, ['from_stage_id' => $stage->getKey(), 'to_stage_id' => $new->getKey(), 'route_id' => $choice['route']->getKey()], null, $reason);

            return $new;
        });
    }

    private function recordBlocked(Grievance $grievance, GrievanceCaseStage $stage): void
    {
        $sent = $stage->warnings_sent ?? [];
        if (! empty($sent['escalation_blocked'])) {
            return;
        }
        $sent['escalation_blocked'] = now()->toIso8601String();
        $stage->forceFill(['warnings_sent' => $sent])->save();

        $this->audit->record(AuditEventType::GrievanceEscalationBlocked, null, $grievance, ['stage_id' => $stage->getKey(), 'reason' => 'no_route']);
        $this->timeline->record($grievance, 'escalation_blocked', null, ['reason' => 'no_route'], $stage->getKey());
        $this->notifier->toStageHandlers($stage->setRelation('grievance', $grievance), 'overdue_no_route');
    }

    /** SLA reminders at the configured thresholds, each sent once per stage. */
    private function sendWarnings(GrievanceCaseStage $stage): int
    {
        $thresholds = $stage->slaProfile?->warning_thresholds ?? ['percent' => [50], 'days_remaining' => [1], 'due_today' => true];
        $sent = $stage->warnings_sent ?? [];
        $toSend = [];

        if ($stage->sla_started_at !== null && $stage->due_at !== null) {
            $total = max(1, $stage->sla_started_at->diffInSeconds($stage->due_at));
            $used = (int) floor(100 * $stage->sla_started_at->diffInSeconds(now()) / $total);
            foreach ((array) ($thresholds['percent'] ?? []) as $percent) {
                if ($used >= (int) $percent && $used < 100 && empty($sent["percent_{$percent}"])) {
                    $toSend["percent_{$percent}"] = 'sla_percent_used';
                }
            }
        }

        $remaining = $this->sla->remainingDays($stage);
        foreach ((array) ($thresholds['days_remaining'] ?? []) as $days) {
            if ($remaining !== null && $remaining > 0 && $remaining <= (int) $days && empty($sent["days_{$days}"])) {
                $toSend["days_{$days}"] = 'sla_days_remaining';
            }
        }
        if (! empty($thresholds['due_today']) && $stage->due_at?->isToday() && ! $this->sla->isOverdue($stage) && empty($sent['due_today'])) {
            $toSend['due_today'] = 'sla_due_today';
        }
        if ($this->sla->isOverdue($stage) && empty($sent['overdue'])) {
            $toSend['overdue'] = 'sla_overdue';
        }

        if ($toSend === []) {
            return 0;
        }

        foreach (array_unique(array_values($toSend)) as $kind) {
            $this->notifier->toStageHandlers($stage, $kind);
        }
        foreach (array_keys($toSend) as $key) {
            $sent[$key] = now()->toIso8601String();
        }
        GrievanceCaseStage::query()->whereKey($stage->getKey())->update(['warnings_sent' => json_encode($sent)]);

        return count($toSend);
    }

    /** Close cases whose appeal window passed without an appeal (when enabled in settings). */
    public function closeExpiredAppealWindows(GrievanceCaseService $cases): int
    {
        $closed = 0;
        Grievance::query()
            ->where('status', GrievanceStatus::DecisionIssued->value)
            ->whereNotNull('appeal_deadline_at')
            ->where('appeal_deadline_at', '<', now())
            ->whereDoesntHave('appeals', fn ($q) => $q->where('status', '!=', 'withdrawn'))
            ->orderBy('appeal_deadline_at')
            ->chunkById(100, function ($grievances) use ($cases, &$closed): void {
                foreach ($grievances as $grievance) {
                    try {
                        $cases->closeBySystem($grievance, 'APPEAL_PERIOD_EXPIRED');
                        $closed++;
                    } catch (Throwable $exception) {
                        Log::error('Grievance auto-close failed', ['grievance' => $grievance->getKey(), 'exception' => $exception->getMessage()]);
                    }
                }
            });

        return $closed;
    }
}
