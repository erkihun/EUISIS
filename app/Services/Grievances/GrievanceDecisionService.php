<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\Grievance\GrievanceApprovalAction;
use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceDecisionType;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievanceSlaPurpose;
use App\Enums\Grievance\GrievanceStageStatus;
use App\Enums\Grievance\GrievanceVoteType;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceDecision;
use App\Models\GrievanceDecisionApproval;
use App\Models\GrievanceDecisionVote;
use App\Models\GrievanceDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decisions (docs/grievance-management.md §8).
 *
 *   draft → [internal review] → pending executive approval → approved
 *        → finalized → issued (through the official decision letter)
 *
 * A returned or rejected version is never overwritten: correcting it creates
 * a new version that supersedes it. Executive approval is required only when
 * an approval rule (or the category) says so, and the approver is resolved
 * from configuration. The preparer and the stage's handlers never approve.
 */
final class GrievanceDecisionService
{
    /** Statuses in which a decision is "in play" on its stage (only one at a time). */
    private const OPEN = ['draft', 'under_internal_review', 'pending_executive_approval', 'resubmitted', 'approved', 'finalized'];

    /** @var list<string> */
    public const CONTENT_FIELDS = ['decision_type', 'findings', 'facts_considered', 'legal_basis', 'analysis', 'decision_text', 'recommendations', 'corrective_action_required', 'disciplinary_referral_recommended'];

    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceApproverResolver $approvers,
        private readonly GrievanceCommitteeService $committees,
        private readonly GrievanceCaseService $cases,
        private readonly GrievanceSlaService $sla,
        private readonly GrievanceSettings $settings,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceNotifier $notifier,
        private readonly GenerateCodeAction $codes,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function createDraft(Grievance $grievance, User $actor, array $data): GrievanceDecision
    {
        $this->access->authorize($this->access->canDraftDecision($actor, $grievance));

        return DB::transaction(function () use ($grievance, $actor, $data): GrievanceDecision {
            $grievance = $this->cases->lock($grievance);
            $stage = $this->cases->lockedCurrentStage($grievance);
            $this->access->authorize($this->access->canDraftDecision($actor, $grievance));
            if (! $stage->isOpen() || $grievance->isFinal()) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
            }
            if ($stage->decisions()->whereIn('status', self::OPEN)->exists()) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.decision_in_progress')]);
            }
            $previous = $stage->decisions()->orderByDesc('version_no')->first();

            $decision = GrievanceDecision::query()->create([
                ...$this->content($data, true),
                'grievance_id' => $grievance->getKey(),
                'case_stage_id' => $stage->getKey(),
                'version_no' => ($previous?->version_no ?? 0) + 1,
                'status' => GrievanceDecisionStatus::Draft,
                'prepared_by' => $actor->getKey(),
                'prepared_by_employee_id' => $actor->employee_id,
                'supersedes_decision_id' => $previous?->getKey(),
            ]);
            $this->cases->setWorkingStatus($grievance->setRelation('currentStage', $stage), GrievanceStageStatus::DecisionDrafting);

            $this->audit->record($previous ? AuditEventType::GrievanceDecisionRevised : AuditEventType::GrievanceDecisionDrafted, $actor, $decision, ['version_no' => $decision->version_no]);
            $this->timeline->record($grievance, 'decision_drafted', $actor, ['version_no' => $decision->version_no], $stage->getKey());

            return $decision;
        });
    }

    /**
     * New version after a return/rejection: copies the content forward so the
     * preparers correct rather than retype; the old version stays as it was.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRevision(GrievanceDecision $decision, User $actor, array $data = []): GrievanceDecision
    {
        $grievance = $decision->grievance;
        $this->access->authorize($grievance !== null && $this->access->canDraftDecision($actor, $grievance));
        if ($decision->case_stage_id !== $grievance->current_stage_id || ! in_array($decision->status, [GrievanceDecisionStatus::ReturnedForCorrection, GrievanceDecisionStatus::Rejected], true)) {
            throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
        }

        return DB::transaction(function () use ($decision, $actor, $data): GrievanceDecision {
            $decision = $this->lockCurrentDecision($decision);
            if (! in_array($decision->status, [GrievanceDecisionStatus::ReturnedForCorrection, GrievanceDecisionStatus::Rejected], true)) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
            }

            return $this->createDraft($decision->grievance, $actor, [...$decision->only(self::CONTENT_FIELDS), ...$data, 'decision_type' => $data['decision_type'] ?? $decision->decision_type?->value]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(GrievanceDecision $decision, User $actor, array $data): GrievanceDecision
    {
        $grievance = $decision->grievance;
        $this->access->authorize($grievance !== null && $this->access->canDraftDecision($actor, $grievance));

        return DB::transaction(function () use ($decision, $actor, $data): GrievanceDecision {
            $decision = $this->lockCurrentDecision($decision);
            if ($decision->status !== GrievanceDecisionStatus::Draft) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.decision_not_editable')]);
            }
            $decision->fill($this->content($data))->save();
            $this->audit->record(AuditEventType::GrievanceDecisionDrafted, $actor, $decision, ['version_no' => $decision->version_no, 'updated' => true]);

            return $decision;
        });
    }

    /** Writer hands the draft to the chairperson / unit lead for internal review. */
    public function submitForReview(GrievanceDecision $decision, User $actor): GrievanceDecision
    {
        $grievance = $decision->grievance;
        $this->access->authorize($grievance !== null && $this->access->canDraftDecision($actor, $grievance));

        return $this->transition($decision, $actor, [GrievanceDecisionStatus::Draft], GrievanceDecisionStatus::UnderInternalReview,
            GrievanceApprovalAction::SubmitForReview, null, AuditEventType::GrievanceDecisionSubmittedForReview,
            fn (GrievanceDecision $d) => $d->forceFill(['submitted_for_review_at' => now()]));
    }

    /** Stage lead endorses (or returns to draft) in internal review. */
    public function internalReview(GrievanceDecision $decision, User $actor, bool $endorse, ?string $comment): GrievanceDecision
    {
        $grievance = $decision->grievance;
        $this->access->authorize($grievance !== null && $this->access->canLead($actor, $grievance, 'grievance_decisions.review'));
        if (! $endorse && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => __('grievances.errors.comment_required')]);
        }

        return $this->transition($decision, $actor, [GrievanceDecisionStatus::UnderInternalReview],
            $endorse ? GrievanceDecisionStatus::UnderInternalReview : GrievanceDecisionStatus::Draft,
            $endorse ? GrievanceApprovalAction::Endorse : GrievanceApprovalAction::ReturnForCorrection, $comment,
            AuditEventType::GrievanceDecisionEndorsed,
            fn (GrievanceDecision $d) => $endorse ? $d->forceFill(['reviewed_by' => $actor->getKey(), 'reviewed_at' => now()]) : $d->forceFill(['reviewed_by' => null, 'reviewed_at' => null]));
    }

    public function castVote(GrievanceDecision $decision, User $actor, GrievanceVoteType $vote, ?string $opinion): GrievanceDecisionVote
    {
        $grievance = $decision->grievance;
        $seat = $this->access->panelSeat($actor, $decision->stage);
        $this->access->authorize($grievance !== null && $seat !== null && $this->access->canReview($actor, $grievance));
        if (! $this->settings->votingEnabled() && ! ($vote === GrievanceVoteType::Dissent && $this->settings->dissentEnabled())) {
            throw ValidationException::withMessages(['vote' => __('grievances.errors.voting_disabled')]);
        }
        if ($vote === GrievanceVoteType::Dissent && ! $this->settings->dissentEnabled()) {
            throw ValidationException::withMessages(['vote' => __('grievances.errors.dissent_disabled')]);
        }
        if (! in_array($decision->status, [GrievanceDecisionStatus::Draft, GrievanceDecisionStatus::UnderInternalReview], true)) {
            throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
        }

        return DB::transaction(function () use ($decision, $actor, $vote, $opinion, $seat): GrievanceDecisionVote {
            $decision = $this->lockCurrentDecision($decision);
            if (! in_array($decision->status, [GrievanceDecisionStatus::Draft, GrievanceDecisionStatus::UnderInternalReview], true)) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
            }
            $record = GrievanceDecisionVote::query()->updateOrCreate(
                ['decision_id' => $decision->getKey(), 'employee_id' => $seat->employee_id],
                ['user_id' => $actor->getKey(), 'vote' => $vote, 'opinion' => $opinion, 'voted_at' => now()],
            );
            $this->audit->record(AuditEventType::GrievanceVoteCast, $actor, $decision, ['vote' => $vote->value]);

            return $record;
        });
    }

    /**
     * Send to the configured executive approver. Only valid when approval is
     * required; otherwise the lead finalizes directly.
     */
    public function submitForApproval(GrievanceDecision $decision, User $actor): GrievanceDecision
    {
        $grievance = $decision->grievance;
        $this->access->authorize($grievance !== null && $this->access->canLead($actor, $grievance, 'grievance_decisions.submit_for_approval'));

        return DB::transaction(function () use ($decision, $actor, $grievance): GrievanceDecision {
            $decision = $this->lockCurrentDecision($decision);
            $grievance = $decision->grievance;
            if (! in_array($decision->status, [GrievanceDecisionStatus::Draft, GrievanceDecisionStatus::UnderInternalReview], true)) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
            }
            $this->assertReady($decision);

            $requirement = $this->approvers->requirementFor($grievance, $decision->stage, $decision->decision_type?->value);
            if (! $requirement['required']) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.approval_not_required')]);
            }
            if ($requirement['problem'] !== null) {
                throw ValidationException::withMessages(['decision' => __($requirement['problem'])]);
            }
            $approvers = $this->approvers->approversFor($requirement['position_id'], $decision->stage->organization_id);
            if ($approvers === []) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.no_approver_available')]);
            }

            $resubmitted = $decision->supersedes_decision_id !== null
                && GrievanceDecision::query()->whereKey($decision->supersedes_decision_id)->where('status', GrievanceDecisionStatus::ReturnedForCorrection->value)->exists();
            $approvalProfile = $requirement['rule']?->approvalSlaProfile
                ?? $this->sla->profileFor(GrievanceSlaPurpose::Approval, $decision->stage->handler_type, $decision->stage->handler_id, $decision->stage->organization_id, $grievance->category_id);

            $decision->forceFill([
                'status' => $resubmitted ? GrievanceDecisionStatus::Resubmitted : GrievanceDecisionStatus::PendingExecutiveApproval,
                'requires_executive_approval' => true,
                'approval_rule_id' => $requirement['rule']?->getKey(),
                'approver_position_id' => $requirement['position_id'],
                'submitted_for_approval_at' => now(),
                // The approval clock is separate: approver delay is never counted against the handler.
                'approval_due_at' => $approvalProfile ? $this->sla->calculateDueDate(now(), $approvalProfile->resolution_days, $approvalProfile->day_type) : null,
            ])->save();
            $this->logAction($decision, $actor, GrievanceApprovalAction::SubmitForApproval, null);
            $this->cases->setWorkingStatus($grievance->setRelation('currentStage', $decision->stage), GrievanceStageStatus::PendingApproval);

            $this->audit->record(AuditEventType::GrievanceDecisionSubmittedForApproval, $actor, $decision, ['version_no' => $decision->version_no, 'approver_position_id' => $requirement['position_id']]);
            $this->timeline->record($grievance, 'decision_submitted_for_approval', $actor, ['version_no' => $decision->version_no], $decision->case_stage_id);
            $this->notifier->toUsers(array_map(fn ($a) => $a['user'], $approvers), 'decision_submitted', $grievance, '/grievances/approvals');

            return $decision;
        });
    }

    public function approve(GrievanceDecision $decision, User $actor, ?string $comment): GrievanceDecision
    {
        $this->access->authorize($actor->can('grievance_decisions.approve') && $this->access->canApprove($actor, $decision));

        return $this->approverAct($decision, $actor, GrievanceApprovalAction::Approve, $comment, GrievanceDecisionStatus::Approved, AuditEventType::GrievanceDecisionApproved);
    }

    public function returnForCorrection(GrievanceDecision $decision, User $actor, string $comment): GrievanceDecision
    {
        $this->access->authorize($actor->can('grievance_decisions.return_for_correction') && $this->access->canApprove($actor, $decision));
        if (trim($comment) === '') {
            throw ValidationException::withMessages(['comment' => __('grievances.errors.comment_required')]);
        }

        return $this->approverAct($decision, $actor, GrievanceApprovalAction::ReturnForCorrection, $comment, GrievanceDecisionStatus::ReturnedForCorrection, AuditEventType::GrievanceDecisionReturned);
    }

    public function reject(GrievanceDecision $decision, User $actor, string $reason): GrievanceDecision
    {
        $this->access->authorize($actor->can('grievance_decisions.reject') && $this->access->canApprove($actor, $decision));
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['comment' => __('grievances.errors.reason_required')]);
        }

        return $this->approverAct($decision, $actor, GrievanceApprovalAction::Reject, $reason, GrievanceDecisionStatus::Rejected, AuditEventType::GrievanceDecisionRejected);
    }

    /**
     * Finalize: an approved decision, or — when no approval is required — an
     * internally ready draft. Assigns the decision number. Issue happens only
     * when the decision letter is issued.
     */
    public function finalize(GrievanceDecision $decision, User $actor): GrievanceDecision
    {
        $grievance = $decision->grievance;
        $this->access->authorize($grievance !== null && $this->access->canLead($actor, $grievance, 'grievance_decisions.finalize'));

        return DB::transaction(function () use ($decision, $actor, $grievance): GrievanceDecision {
            $decision = $this->lockCurrentDecision($decision);
            $grievance = $decision->grievance;

            if ($decision->status === GrievanceDecisionStatus::Approved) {
                // Approved: ready.
            } elseif (in_array($decision->status, [GrievanceDecisionStatus::Draft, GrievanceDecisionStatus::UnderInternalReview], true)) {
                $requirement = $this->approvers->requirementFor($grievance, $decision->stage, $decision->decision_type?->value);
                if ($requirement['required']) {
                    throw ValidationException::withMessages(['decision' => __('grievances.errors.approval_required')]);
                }
                $this->assertReady($decision);
            } else {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
            }

            $decision->forceFill([
                'status' => GrievanceDecisionStatus::Finalized,
                'decision_no' => $decision->decision_no ?? $this->codes->execute(CodeRuleEntityType::GrievanceDecision, ['organization_id' => $grievance->organization_id], $actor, null, 'decision_no', $decision->getKey()),
                'finalized_by' => $actor->getKey(),
                'finalized_at' => now(),
            ])->save();
            $decision->stage->forceFill(['decision_id' => $decision->getKey()])->save();
            $this->logAction($decision, $actor, GrievanceApprovalAction::Finalize, null);

            $this->audit->record(AuditEventType::GrievanceDecisionFinalized, $actor, $decision, ['decision_no' => $decision->decision_no]);
            $this->timeline->record($grievance, 'decision_finalized', $actor, ['decision_no' => $decision->decision_no], $decision->case_stage_id);

            return $decision;
        });
    }

    /**
     * Called by the correspondence service when the decision letter is
     * issued (inside its transaction): the decision becomes ISSUED, the stage
     * is resolved and the appeal window opens.
     */
    public function markIssued(GrievanceDecision $decision, ?User $actor): void
    {
        $decision = $this->lockCurrentDecision($decision);
        if ($decision->status !== GrievanceDecisionStatus::Finalized) {
            throw ValidationException::withMessages(['decision' => __('grievances.errors.decision_not_finalized')]);
        }
        $grievance = Grievance::query()->whereKey($decision->grievance_id)->lockForUpdate()->firstOrFail();
        $stage = GrievanceCaseStage::query()->whereKey($decision->case_stage_id)->lockForUpdate()->firstOrFail();

        $decision->forceFill(['status' => GrievanceDecisionStatus::Issued, 'issued_at' => now()])->save();
        GrievanceDecision::query()->where('case_stage_id', $stage->getKey())->whereKeyNot($decision->getKey())
            ->whereIn('status', ['returned_for_correction', 'rejected'])->update(['status' => GrievanceDecisionStatus::Superseded->value]);

        $stage->forceFill(['status' => GrievanceStageStatus::Resolved, 'completed_at' => now(), 'decision_id' => $decision->getKey()])->save();

        $appealDeadline = null;
        if ($this->settings->appealEnabled() && $stage->handler_type !== GrievanceHandlerType::ExternalAuthority) {
            $profile = $this->sla->profileFor(GrievanceSlaPurpose::AppealFiling, $stage->handler_type, $stage->handler_id, $stage->organization_id, $grievance->category_id);
            $appealDeadline = $profile ? $this->sla->calculateDueDate(now(), $profile->resolution_days, $profile->day_type) : null;
        }
        $grievance->forceFill([
            'status' => GrievanceStatus::DecisionIssued,
            'resolved_at' => now(),
            'appeal_deadline_at' => $appealDeadline,
        ])->save();

        $this->audit->record(AuditEventType::GrievanceDecisionIssued, $actor, $decision, ['decision_no' => $decision->decision_no, 'appeal_deadline_at' => $appealDeadline?->toIso8601String()]);
        $this->timeline->record($grievance, 'decision_issued', $actor, ['decision_no' => $decision->decision_no, 'appeal_deadline_at' => $appealDeadline?->toIso8601String()], $stage->getKey());
        $this->notifier->toComplainant($grievance, 'decision_issued');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function approverAct(GrievanceDecision $decision, User $actor, GrievanceApprovalAction $action, ?string $comment, GrievanceDecisionStatus $to, AuditEventType $event): GrievanceDecision
    {
        return DB::transaction(function () use ($decision, $actor, $action, $comment, $to, $event): GrievanceDecision {
            // Lock so two approvers (or a double click) cannot both act.
            $decision = $this->lockCurrentDecision($decision);
            if (! $this->access->isPendingApproval($decision)) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
            }
            $delegation = $this->approvers->authorityOf($actor, $decision);
            if ($delegation === false) {
                $this->access->authorize(false);
            }

            $updates = ['status' => $to];
            if ($to === GrievanceDecisionStatus::Approved) {
                $updates += ['approved_by' => $actor->getKey(), 'approved_by_employee_id' => $actor->employee_id, 'approved_at' => now()];
            } elseif ($to === GrievanceDecisionStatus::Rejected) {
                $updates += ['rejected_by' => $actor->getKey(), 'rejected_at' => now()];
            } else {
                $updates += ['returned_at' => now()];
            }
            $decision->forceFill($updates)->save();
            $this->logAction($decision, $actor, $action, $comment, $delegation instanceof GrievanceDelegation ? $delegation : null);

            $grievance = $decision->grievance;
            $stageStatus = match ($to) {
                GrievanceDecisionStatus::Approved => GrievanceStageStatus::DecisionDrafting,
                GrievanceDecisionStatus::ReturnedForCorrection => GrievanceStageStatus::ReturnedForCorrection,
                default => GrievanceStageStatus::DecisionDrafting,
            };
            $this->cases->setWorkingStatus($grievance->setRelation('currentStage', $decision->stage), $stageStatus);

            // Approval comments are internal; the complainant never sees them.
            $this->audit->record($event, $actor, $decision, ['version_no' => $decision->version_no, 'delegation_id' => $delegation instanceof GrievanceDelegation ? $delegation->getKey() : null]);
            $this->timeline->record($grievance, 'decision_'.$action->value, $actor, ['version_no' => $decision->version_no], $decision->case_stage_id);
            $this->notifier->toStageHandlers($decision->stage->setRelation('grievance', $grievance), match ($to) {
                GrievanceDecisionStatus::Approved => 'decision_approved',
                GrievanceDecisionStatus::Rejected => 'decision_rejected',
                default => 'decision_returned',
            });

            return $decision;
        });
    }

    /**
     * @param  list<GrievanceDecisionStatus>  $from
     */
    private function transition(GrievanceDecision $decision, User $actor, array $from, GrievanceDecisionStatus $to, GrievanceApprovalAction $action, ?string $comment, AuditEventType $event, ?\Closure $mutate = null): GrievanceDecision
    {
        return DB::transaction(function () use ($decision, $actor, $from, $to, $action, $comment, $event, $mutate): GrievanceDecision {
            $decision = $this->lockCurrentDecision($decision);
            if (! in_array($decision->status, $from, true)) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
            }
            $decision->status = $to;
            if ($mutate !== null) {
                $mutate($decision);
            }
            $decision->save();
            $this->logAction($decision, $actor, $action, $comment);
            $this->audit->record($event, $actor, $decision, ['status' => $to->value, 'action' => $action->value]);

            return $decision;
        });
    }

    /** Lock in the same order as escalation and reject historical-stage mutations. */
    private function lockCurrentDecision(GrievanceDecision $decision): GrievanceDecision
    {
        $grievance = Grievance::query()->whereKey($decision->grievance_id)->lockForUpdate()->firstOrFail();
        $stage = GrievanceCaseStage::query()->whereKey($decision->case_stage_id)->lockForUpdate()->firstOrFail();
        $decision = GrievanceDecision::query()->whereKey($decision->getKey())->lockForUpdate()->firstOrFail();
        if ($grievance->current_stage_id !== $stage->getKey() || $grievance->isFinal() || ! $stage->isOpen()) {
            throw ValidationException::withMessages(['decision' => __('grievances.errors.stale')]);
        }

        return $decision->setRelation('grievance', $grievance->setRelation('currentStage', $stage))->setRelation('stage', $stage);
    }

    /** Content present and — for a committee — quorum met. */
    private function assertReady(GrievanceDecision $decision): void
    {
        if ($decision->decision_type === null || trim((string) $decision->decision_text) === '') {
            throw ValidationException::withMessages(['decision' => __('grievances.errors.decision_incomplete')]);
        }

        $stage = $decision->stage;
        if ($stage->handler_type === GrievanceHandlerType::Committee) {
            $votes = $this->settings->votingEnabled() ? $decision->votes()->count() : null;
            $quorum = $this->committees->quorum($stage, $votes);
            $decision->forceFill(['quorum_met' => $quorum['met']])->save();
            if (! $quorum['met']) {
                throw ValidationException::withMessages(['decision' => __('grievances.errors.quorum_not_met', ['present' => $quorum['present'], 'required' => $quorum['required']])]);
            }
        }
    }

    private function logAction(GrievanceDecision $decision, User $actor, GrievanceApprovalAction $action, ?string $comment, ?GrievanceDelegation $delegation = null): void
    {
        $placement = $actor->employee_id ? app(GrievanceHandlerRegistry::class)->placementOf($this->access->employeeOf($actor)) : null;

        GrievanceDecisionApproval::query()->create([
            'decision_id' => $decision->getKey(),
            'action' => $action,
            'actor_user_id' => $actor->getKey(),
            'actor_employee_id' => $actor->employee_id,
            'actor_position_id' => $placement['position_id'] ?? null,
            'delegation_id' => $delegation?->getKey(),
            'comment' => $comment,
            'acted_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function content(array $data, bool $creating = false): array
    {
        $content = array_intersect_key($data, array_flip(self::CONTENT_FIELDS));
        if (isset($content['decision_type']) && $content['decision_type'] !== null) {
            $content['decision_type'] = GrievanceDecisionType::from((string) $content['decision_type']);
        }
        if ($creating) {
            $content['decision_text'] ??= '';
        }

        return $content;
    }
}
