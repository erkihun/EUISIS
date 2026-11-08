<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnit;
use Modules\Feedback\Domain\Models\PublicComplaintStage;

/**
 * The hearing stages of a public complaint (ADR 0035, owner request
 * 2026-11-08). The complaint committee answers first. A complainant who is
 * not satisfied appeals: the complaint becomes an appeal (አቤቱታ) heard by the
 * appeal committee, which upholds or overturns the first answer. A
 * complainant still not satisfied takes it to the head of the institution,
 * whose decision is final. An institution without an appeal committee sends
 * the appeal straight to its head.
 *
 * The complainant answers each decision on the public tracking page: accept
 * (the complaint closes) or appeal (with a reason). With an appeal period
 * set, silence past it closes the complaint; without one the appeal stays
 * open until staff close the complaint. Whoever decided an earlier stage
 * never decides a later one.
 */
final class PublicComplaintStageService
{
    public function __construct(
        private readonly PublicFeedbackWorkflow $workflow,
        private readonly PublicFeedbackAssignmentService $assignments,
        private readonly PublicFeedbackSettings $settings,
        private readonly PublicFeedbackNotifier $notifier,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @return Collection<int, PublicComplaintStage> oldest stage first */
    public function stages(EmployeeServiceFeedback $submission): Collection
    {
        return PublicComplaintStage::query()->where('submission_id', $submission->getKey())->orderBy('stage')->get();
    }

    public function current(EmployeeServiceFeedback $submission): ?PublicComplaintStage
    {
        return PublicComplaintStage::query()->where('submission_id', $submission->getKey())->orderByDesc('stage')->first();
    }

    /** The stage the complaint is in now: 1 until an appeal opens a later one. */
    public function currentNumber(EmployeeServiceFeedback $submission): int
    {
        return $this->current($submission)?->stage ?? PublicComplaintStage::COMMITTEE;
    }

    /**
     * Record the stage's decision and move the complaint to resolved. The
     * response is what the complainant reads; a later stage also says
     * whether it upholds or overturns the answer before it.
     *
     * @throws ValidationException
     */
    public function decide(EmployeeServiceFeedback $submission, User $actor, ?string $decision, ?string $response, ?Request $request = null): PublicComplaintStage
    {
        abort_unless($submission->type === ServiceFeedbackType::Complaint, 404);
        $response = trim((string) $response);
        if ($response === '') {
            throw ValidationException::withMessages(['reason' => __('service-feedback.stages.response_required')]);
        }
        $stage = $this->current($submission);
        $number = $stage?->stage ?? PublicComplaintStage::COMMITTEE;
        if ($stage?->isDecided()) {
            throw ValidationException::withMessages(['status' => __('service-feedback.transition_not_allowed')]);
        }
        if ($number === PublicComplaintStage::COMMITTEE) {
            $decision = 'answered';
        } elseif (! in_array($decision, ['upheld', 'overturned'], true)) {
            throw ValidationException::withMessages(['stage_decision' => __('service-feedback.stages.decision_required')]);
        }
        if ($this->decidedEarlier($actor, $submission)) {
            throw ValidationException::withMessages(['status' => __('service-feedback.stages.conflict')]);
        }

        return DB::transaction(function () use ($submission, $actor, $decision, $response, $request, $stage, $number): PublicComplaintStage {
            $this->workflow->transition($submission, ServiceFeedbackStatus::Resolved, $actor, $response, $request);
            $stage ??= new PublicComplaintStage(['submission_id' => $submission->getKey(), 'stage' => $number, 'opened_at' => $submission->submitted_at ?? now()]);
            $days = $this->settings->complaintAppealDays();
            $stage->forceFill([
                'decision' => $decision,
                'response' => $response,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                // The head's decision is final: nothing to appeal.
                'appeal_deadline_at' => $number < PublicComplaintStage::HEAD && $days !== null ? now()->addDays($days) : null,
            ])->save();
            $this->audit->execute(
                eventType: AuditEventType::ServiceFeedbackStatusChanged,
                actor: $actor,
                auditable: $submission,
                organizationId: $submission->organization_id,
                newValues: ['complaint_stage' => $number, 'decision' => $decision],
                reason: 'Complaint stage decided',
                request: $request,
            );

            return $stage;
        });
    }

    /**
     * Where an appeal from the current decision goes: the appeal committee
     * after the first answer (or the head when the institution has no appeal
     * committee), the head after the appeal committee, nowhere after the head.
     */
    public function nextStage(EmployeeServiceFeedback $submission): ?int
    {
        $current = $this->currentNumber($submission);

        return match (true) {
            $current === PublicComplaintStage::COMMITTEE && $this->stageUnit($submission, PublicComplaintStage::APPEAL_COMMITTEE) !== null => PublicComplaintStage::APPEAL_COMMITTEE,
            $current < PublicComplaintStage::HEAD => PublicComplaintStage::HEAD,
            default => null,
        };
    }

    /** The institution's active unit serving a stage (its complaint, appeal or head's office unit). */
    public function stageUnit(EmployeeServiceFeedback $submission, int $stage): ?PublicCaseHandlingUnit
    {
        if ($submission->organization_id === null) {
            return null;
        }

        return PublicCaseHandlingUnit::query()->where('organization_id', $submission->organization_id)
            ->where('case_type', 'complaint')->where('stage', PublicComplaintStage::UNIT_STAGES[$stage])->where('status', 'active')
            ->orderBy('created_at')->first();
    }

    /** What the complainant may do now on the tracking page. */
    public function canAccept(EmployeeServiceFeedback $submission): bool
    {
        $stage = $this->current($submission);

        return $submission->status === ServiceFeedbackStatus::Resolved && $stage !== null && $stage->awaitsComplainant();
    }

    public function canAppeal(EmployeeServiceFeedback $submission): bool
    {
        $stage = $this->current($submission);

        return $submission->status === ServiceFeedbackStatus::Resolved && $stage !== null && $stage->appealOpen() && $this->nextStage($submission) !== null;
    }

    /** The complainant accepts the decision: the complaint closes. */
    public function accept(EmployeeServiceFeedback $submission, ?Request $request = null): void
    {
        if (! $this->canAccept($submission)) {
            throw ValidationException::withMessages(['action' => __('service-feedback.stages.not_open')]);
        }
        DB::transaction(function () use ($submission, $request): void {
            $this->current($submission)?->forceFill(['citizen_outcome' => 'accepted', 'citizen_responded_at' => now()])->save();
            $this->workflow->transition($submission, ServiceFeedbackStatus::Closed, null, __('service-feedback.stages.history.accepted'), $request);
        });
    }

    /**
     * The complainant appeals with a reason: the next stage opens and the
     * complaint goes to its unit (or waits unrouted for a coordinator when
     * the institution has none).
     */
    public function appeal(EmployeeServiceFeedback $submission, string $reason, ?Request $request = null): PublicComplaintStage
    {
        $reason = trim($reason);
        $next = $this->nextStage($submission);
        if (! $this->canAppeal($submission) || $next === null) {
            throw ValidationException::withMessages(['action' => __('service-feedback.stages.not_open')]);
        }

        return DB::transaction(function () use ($submission, $reason, $request, $next): PublicComplaintStage {
            $this->current($submission)?->forceFill(['citizen_outcome' => 'appealed', 'citizen_responded_at' => now()])->save();
            $stage = PublicComplaintStage::query()->create([
                'submission_id' => $submission->getKey(),
                'stage' => $next,
                'opened_at' => now(),
                'appeal_reason' => $reason,
            ]);
            $history = __('service-feedback.stages.history.appealed_'.$next);
            $unit = $this->stageUnit($submission, $next);

            if ($unit === null) {
                $this->workflow->transition($submission, ServiceFeedbackStatus::Unrouted, null, $history, $request);
                $this->notifier->unrouted($submission);

                return $stage;
            }

            $this->workflow->transition($submission, ServiceFeedbackStatus::Assigned, null, $history, $request);
            $days = $this->settings->complaintAppealResponseDays();
            $this->assignments->assign(
                $submission,
                PublicFeedbackHandlerType::OrganizationUnit,
                (string) $unit->organization_unit_id,
                null,
                $history,
                null,
                $request,
                1,
                false,
                $days !== null ? now()->addDays($days) : null,
            );

            return $stage;
        });
    }

    /** Close the complaints whose appeal period passed with no answer from the complainant. */
    public function closeLapsed(): int
    {
        $closed = 0;
        PublicComplaintStage::query()->whereNotNull('decided_at')->whereNull('citizen_outcome')
            ->whereNotNull('appeal_deadline_at')->where('appeal_deadline_at', '<', now())
            ->orderBy('appeal_deadline_at')->limit(500)->get()
            ->each(function (PublicComplaintStage $stage) use (&$closed): void {
                $submission = EmployeeServiceFeedback::query()->find($stage->submission_id);
                if ($submission === null || $submission->status !== ServiceFeedbackStatus::Resolved || $this->current($submission)?->getKey() !== $stage->getKey()) {
                    $stage->forceFill(['citizen_outcome' => 'lapsed'])->save();

                    return;
                }
                DB::transaction(function () use ($stage, $submission): void {
                    $stage->forceFill(['citizen_outcome' => 'lapsed'])->save();
                    $this->workflow->transition($submission, ServiceFeedbackStatus::Closed, null, __('service-feedback.stages.history.lapsed'));
                });
                $closed++;
            });

        return $closed;
    }

    /**
     * Staff may close a decided complaint unless the complainant still has an
     * appeal period running. Without a period set, closing ends the right to appeal.
     */
    public function closable(EmployeeServiceFeedback $submission): bool
    {
        if ($submission->type !== ServiceFeedbackType::Complaint) {
            return true;
        }
        $stage = $this->current($submission);

        return $stage === null || ! ($stage->appealOpen() && $stage->appeal_deadline_at !== null && $this->nextStage($submission) !== null);
    }

    /** Whoever decided an earlier stage of this complaint does not decide a later one. */
    public function decidedEarlier(User $user, EmployeeServiceFeedback $submission): bool
    {
        if ($submission->type !== ServiceFeedbackType::Complaint) {
            return false;
        }
        $current = $this->currentNumber($submission);

        return $current > PublicComplaintStage::COMMITTEE && PublicComplaintStage::query()
            ->where('submission_id', $submission->getKey())->where('stage', '<', $current)
            ->where('decided_by', $user->getKey())->exists();
    }

    /**
     * The stages for the staff screen.
     *
     * @return array{current: int, decision_required: bool, next: int|null, stages: list<array<string, mixed>>}
     */
    public function present(EmployeeServiceFeedback $submission, bool $forPublic = false): array
    {
        $stages = $this->stages($submission);
        $current = $stages->last();

        return [
            'current' => $current?->stage ?? PublicComplaintStage::COMMITTEE,
            'decision_required' => $current !== null && $current->stage > PublicComplaintStage::COMMITTEE && ! $current->isDecided(),
            'next' => $this->nextStage($submission),
            'stages' => $stages->map(fn (PublicComplaintStage $s): array => array_filter([
                'stage' => $s->stage,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'appeal_reason' => $s->appeal_reason,
                'decision' => $s->decision,
                'response' => $s->response,
                'decided_by' => $forPublic ? null : $s->decider?->name,
                'decided_at' => $s->decided_at?->toIso8601String(),
                'appeal_deadline_at' => $s->appeal_deadline_at?->toIso8601String(),
                'citizen_outcome' => $s->citizen_outcome,
                'citizen_responded_at' => $s->citizen_responded_at?->toIso8601String(),
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }
}
