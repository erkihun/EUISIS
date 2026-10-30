<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievanceSlaDayType;
use App\Enums\Grievance\GrievanceSlaPauseReason;
use App\Enums\Grievance\GrievanceSlaPauseStatus;
use App\Enums\Grievance\GrievanceSlaPurpose;
use App\Enums\Grievance\GrievanceSlaStartPoint;
use App\Enums\Grievance\GrievanceSlaState;
use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceSlaPause;
use App\Models\GrievanceSlaProfile;
use App\Models\User;
use App\Services\Calendar\WorkingDayCalculator;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stage deadlines (docs/grievance-management.md §6).
 *
 *  - Each handler level has its own profile (never one global number).
 *  - The profile is snapshotted onto the stage when the clock starts; a later
 *    policy change never rewrites a historical deadline.
 *  - When the clock starts is the profile's start point (assignment, receipt
 *    or acceptance) — a policy choice, not code.
 *  - Working days come from the shared public-holiday calendar.
 *  - A pause is approved, audited, and extends the deadline by the paused
 *    working (or calendar) days; the stage keeps its original due date.
 */
final class GrievanceSlaService
{
    public function __construct(
        private readonly WorkingDayCalculator $calendar,
        private readonly GrievanceSettings $settings,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
    ) {}

    /**
     * Most specific active profile for a purpose: exact handler > handler
     * type > any, then category, then organization, then priority.
     */
    public function profileFor(GrievanceSlaPurpose $purpose, ?GrievanceHandlerType $handlerType, ?string $handlerId, ?string $organizationId, ?string $categoryId, ?CarbonInterface $on = null): ?GrievanceSlaProfile
    {
        $day = ($on ?? now())->toDateString();

        return GrievanceSlaProfile::query()
            ->where('purpose', $purpose->value)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->where(fn ($q) => $q->whereNull('handler_type')->orWhere(fn ($h) => $h->where('handler_type', $handlerType?->value)
                ->where(fn ($i) => $i->whereNull('handler_id')->orWhere('handler_id', $handlerId))))
            ->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', $categoryId))
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->get()
            ->sortBy(fn (GrievanceSlaProfile $p) => [
                $p->handler_id !== null ? 0 : ($p->handler_type !== null ? 1 : 2),
                $p->category_id !== null ? 0 : 1,
                $p->organization_id !== null ? 0 : 1,
                $p->priority,
            ])
            ->first();
    }

    /** Copy the profile onto a new stage; start the clock now if it runs from assignment. */
    public function snapshot(GrievanceCaseStage $stage, ?GrievanceSlaProfile $profile): void
    {
        if ($profile === null) {
            $stage->forceFill(['sla_profile_id' => null, 'sla_days' => null, 'sla_day_type' => null, 'sla_start_point' => null, 'auto_escalate' => false]);

            return;
        }

        $stage->forceFill([
            'sla_profile_id' => $profile->getKey(),
            'sla_days' => $profile->resolution_days,
            'sla_day_type' => $profile->day_type,
            'sla_start_point' => $profile->start_point,
            'auto_escalate' => $profile->auto_escalate,
        ]);

        if ($profile->start_point === GrievanceSlaStartPoint::OnAssignment) {
            $this->startClock($stage, now());
        }
    }

    /** Start the clock if the stage's start point is $point and it has not started. */
    public function startIfDue(GrievanceCaseStage $stage, GrievanceSlaStartPoint $point): void
    {
        if ($stage->sla_started_at === null && $stage->sla_days !== null && $stage->sla_start_point === $point) {
            $this->startClock($stage, now());
        }
    }

    public function startClock(GrievanceCaseStage $stage, CarbonInterface $at): void
    {
        $due = $this->calculateDueDate(Carbon::instance($at), (int) $stage->sla_days, $stage->sla_day_type ?? GrievanceSlaDayType::WorkingDays);
        $stage->forceFill(['sla_started_at' => $at, 'due_at' => $due, 'original_due_at' => $due]);
    }

    public function calculateDueDate(Carbon $start, int $days, GrievanceSlaDayType $type): Carbon
    {
        return $type === GrievanceSlaDayType::CalendarDays
            ? $this->calendar->addCalendarDays($start, $days)
            : $this->calendar->addWorkingDays($start, $days, $this->settings->workWeekDays());
    }

    /** Days left (0 when due today, negative when overdue) in the stage's day type. */
    public function remainingDays(GrievanceCaseStage $stage, ?CarbonInterface $now = null): ?int
    {
        if ($stage->due_at === null) {
            return null;
        }
        $now = Carbon::instance($now ?? now());
        $due = $stage->due_at->copy();

        if ($due->lt($now)) {
            return -$this->countDays($due, $now, $stage->sla_day_type);
        }

        return $this->countDays($now, $due, $stage->sla_day_type);
    }

    public function countDays(Carbon $from, Carbon $to, ?GrievanceSlaDayType $type): int
    {
        return $type === GrievanceSlaDayType::CalendarDays
            ? (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay())
            : $this->calendar->workingDaysBetween($from, $to, $this->settings->workWeekDays());
    }

    public function isOverdue(GrievanceCaseStage $stage, ?CarbonInterface $now = null): bool
    {
        return $stage->due_at !== null && $stage->due_at->lt($now ?? now());
    }

    public function state(GrievanceCaseStage $stage, ?bool $paused = null): GrievanceSlaState
    {
        if (! $stage->isOpen()) {
            return GrievanceSlaState::Stopped;
        }
        if ($paused ?? $stage->isPaused()) {
            return GrievanceSlaState::Paused;
        }
        if ($stage->due_at === null) {
            return GrievanceSlaState::NoDeadline;
        }
        if ($this->isOverdue($stage)) {
            return GrievanceSlaState::Overdue;
        }
        if ($stage->due_at->isToday()) {
            return GrievanceSlaState::DueToday;
        }

        return ($this->remainingDays($stage) ?? PHP_INT_MAX) <= $this->settings->dueSoonDays()
            ? GrievanceSlaState::DueSoon
            : GrievanceSlaState::OnTrack;
    }

    /**
     * @return array{state: string, due_at: string|null, original_due_at: string|null, remaining_days: int|null, day_type: string|null, sla_days: int|null, paused_days: int, started_at: string|null}
     */
    public function summary(GrievanceCaseStage $stage): array
    {
        return [
            'state' => $this->state($stage)->value,
            'due_at' => $stage->due_at?->toIso8601String(),
            'original_due_at' => $stage->original_due_at?->toIso8601String(),
            'remaining_days' => $stage->isOpen() ? $this->remainingDays($stage) : null,
            'day_type' => $stage->sla_day_type?->value,
            'sla_days' => $stage->sla_days,
            'paused_days' => (int) $stage->paused_days,
            'started_at' => $stage->sla_started_at?->toIso8601String(),
        ];
    }

    // ── Pause / resume ───────────────────────────────────────────────────────

    /**
     * Request a pause. A holder of grievances.sla_pause starts it at once
     * (still audited); anyone else only files a request for approval.
     */
    public function requestPause(GrievanceCaseStage $stage, GrievanceSlaPauseReason $reason, ?string $notes, User $actor): GrievanceSlaPause
    {
        return DB::transaction(function () use ($stage, $reason, $notes, $actor): GrievanceSlaPause {
            $stage = GrievanceCaseStage::query()->whereKey($stage->getKey())->lockForUpdate()->firstOrFail();
            if (! $stage->isOpen() || $stage->due_at === null) {
                throw ValidationException::withMessages(['stage' => __('grievances.errors.sla_not_running')]);
            }
            if ($stage->pauses()->whereIn('status', [GrievanceSlaPauseStatus::Requested->value, GrievanceSlaPauseStatus::Active->value])->exists()) {
                throw ValidationException::withMessages(['stage' => __('grievances.errors.pause_exists')]);
            }

            $pause = GrievanceSlaPause::query()->create([
                'grievance_id' => $stage->grievance_id,
                'case_stage_id' => $stage->getKey(),
                'pause_reason' => $reason,
                'status' => GrievanceSlaPauseStatus::Requested,
                'notes' => $notes,
                'requested_by' => $actor->getKey(),
                'requested_at' => now(),
            ]);
            $this->audit->record(AuditEventType::GrievanceSlaPauseRequested, $actor, $pause, ['reason' => $reason->value, 'stage_id' => $stage->getKey()]);

            if ($actor->can('grievances.sla_pause')) {
                $this->activate($pause, $stage, $actor);
            }

            return $pause->refresh();
        });
    }

    public function approvePause(GrievanceSlaPause $pause, User $approver): GrievanceSlaPause
    {
        return DB::transaction(function () use ($pause, $approver): GrievanceSlaPause {
            $pause = GrievanceSlaPause::query()->whereKey($pause->getKey())->lockForUpdate()->firstOrFail();
            if ($pause->status !== GrievanceSlaPauseStatus::Requested) {
                throw ValidationException::withMessages(['pause' => __('grievances.errors.stale')]);
            }
            $stage = GrievanceCaseStage::query()->whereKey($pause->case_stage_id)->lockForUpdate()->firstOrFail();
            $this->activate($pause, $stage, $approver);

            return $pause->refresh();
        });
    }

    public function rejectPause(GrievanceSlaPause $pause, User $approver, ?string $notes): GrievanceSlaPause
    {
        return DB::transaction(function () use ($pause, $approver, $notes): GrievanceSlaPause {
            $pause = GrievanceSlaPause::query()->whereKey($pause->getKey())->lockForUpdate()->firstOrFail();
            if ($pause->status !== GrievanceSlaPauseStatus::Requested) {
                throw ValidationException::withMessages(['pause' => __('grievances.errors.stale')]);
            }
            $pause->forceFill(['status' => GrievanceSlaPauseStatus::Rejected, 'approved_by' => $approver->getKey(), 'ended_at' => now(), 'notes' => trim(($pause->notes ?? '')."\n".($notes ?? ''))])->save();
            $this->audit->record(AuditEventType::GrievanceSlaPauseRejected, $approver, $pause, ['stage_id' => $pause->case_stage_id]);

            return $pause;
        });
    }

    /** End an active pause and extend the deadline by the paused days. */
    public function resume(GrievanceSlaPause $pause, ?User $actor): GrievanceSlaPause
    {
        return DB::transaction(function () use ($pause, $actor): GrievanceSlaPause {
            $pause = GrievanceSlaPause::query()->whereKey($pause->getKey())->lockForUpdate()->firstOrFail();
            if ($pause->status !== GrievanceSlaPauseStatus::Active) {
                return $pause;
            }
            $stage = GrievanceCaseStage::query()->whereKey($pause->case_stage_id)->lockForUpdate()->firstOrFail();

            $now = now();
            $pausedDays = $this->countDays($pause->started_at->copy(), $now->copy(), $stage->sla_day_type);
            $before = $stage->due_at;
            $after = $before === null ? null : (
                $stage->sla_day_type === GrievanceSlaDayType::CalendarDays
                    ? $before->copy()->addDays($pausedDays)->endOfDay()
                    : ($pausedDays > 0 ? $this->calendar->addWorkingDays($before->copy(), $pausedDays, $this->settings->workWeekDays()) : $before->copy())
            );

            $stage->forceFill(['due_at' => $after, 'paused_days' => (int) $stage->paused_days + $pausedDays, 'warnings_sent' => null])->save();
            $pause->forceFill([
                'status' => GrievanceSlaPauseStatus::Ended,
                'ended_at' => $now,
                'ended_by' => $actor?->getKey(),
                'due_at_after' => $after,
                'paused_days' => $pausedDays,
            ])->save();

            $grievance = Grievance::query()->find($stage->grievance_id);
            $this->audit->record(AuditEventType::GrievanceSlaResumed, $actor, $pause, [
                'stage_id' => $stage->getKey(),
                'original_due_at' => $stage->original_due_at?->toIso8601String(),
                'due_at_before' => $before?->toIso8601String(),
                'paused_days' => $pausedDays,
                'due_at_after' => $after?->toIso8601String(),
            ]);
            if ($grievance !== null) {
                $this->timeline->record($grievance, 'sla_resumed', $actor, ['paused_days' => $pausedDays, 'due_at' => $after?->toIso8601String()], $stage->getKey());
            }

            return $pause;
        });
    }

    private function activate(GrievanceSlaPause $pause, GrievanceCaseStage $stage, User $approver): void
    {
        $pause->forceFill([
            'status' => GrievanceSlaPauseStatus::Active,
            'approved_by' => $approver->getKey(),
            'started_at' => now(),
            'due_at_before' => $stage->due_at,
        ])->save();

        $this->audit->record(AuditEventType::GrievanceSlaPaused, $approver, $pause, [
            'stage_id' => $stage->getKey(),
            'reason' => $pause->pause_reason->value,
            'due_at_before' => $stage->due_at?->toIso8601String(),
        ]);
        $grievance = Grievance::query()->find($stage->grievance_id);
        if ($grievance !== null) {
            $this->timeline->record($grievance, 'sla_paused', $approver, ['reason' => $pause->pause_reason->value], $stage->getKey());
        }
    }
}
