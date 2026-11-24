<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\ArtifactQueue;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\EventOutbox;
use Modules\Reporting\Application\Ports\NotificationPublisher;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Ports\WorkingCalendar;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Contracts\Events\ReportApprovedV1;
use Modules\Reporting\Domain\Enums\ReviewDecision;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Domain\Workflow\WorkflowRules;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Comment;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Draft;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewAssignment;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;

/**
 * Review of a submitted version through its sequential steps, by the
 * receiving side. Nothing here touches the first-submission facts: a slow
 * reviewer, a return or a rejection never changes the submitter's timeliness.
 */
final readonly class ReviewReports
{
    public function __construct(
        private WorkingCalendar $calendar,
        private ReportingAccessResolver $access,
        private EventOutbox $outbox,
        private ArtifactQueue $artifacts,
        private NotificationPublisher $notifications,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    /**
     * Decides the open step of a submission.
     *
     * - Approve: opens the next step, or approves the report at the last one.
     * - Return: closes this version and opens a new editable version for
     *   resubmission, with its own revision deadline.
     * - Reject: ends the workflow for this obligation.
     *
     * Returning and rejecting need a reason; the reviewer cannot be the
     * person who submitted the version unless the report type allows it.
     */
    public function decide(ActorContext $actor, string $submissionId, ReviewDecision $decision, ?string $reason, int $expectedStepNo): Submission
    {
        $this->settings->assertWritable();

        return DB::transaction(function () use ($actor, $submissionId, $decision, $reason, $expectedStepNo): Submission {
            $obligation = Obligation::query()->lockForUpdate()->findOrFail(Submission::query()->findOrFail($submissionId)->obligation_id);
            $submission = Submission::query()->lockForUpdate()->findOrFail($submissionId);
            $step = ReviewStep::query()->where('submission_id', $submission->id)->where('status', 'pending')->lockForUpdate()->first();
            // The step the reviewer saw must still be the open one (two reviewers, or a double click).
            if ($step === null || $step->step_no !== $expectedStepNo || $obligation->latest_submission_id !== $submission->id) {
                throw new WorkflowViolation('step_already_decided');
            }
            ReportingGuard::requireReceivingSide($actor, $obligation, $step->required_permission);

            $cycle = Cycle::query()->findOrFail($obligation->cycle_id);
            $type = ReportType::query()->findOrFail($obligation->report_type_id);
            $isLast = ! ReviewStep::query()->where('submission_id', $submission->id)->where('step_no', '>', $step->step_no)->exists();
            $now = CarbonImmutable::now('UTC');

            $next = WorkflowRules::afterDecision(
                $obligation->workflow_status, $decision, $isLast, $reason,
                (int) $submission->submitted_by === $actor->userId, (bool) $type->allow_self_approval,
            );

            $step->forceFill([
                'status' => 'decided', 'decision' => $decision->value, 'reason' => $reason === null ? null : trim($reason),
                'decided_by' => $actor->userId, 'decided_by_name' => $actor->name, 'decided_at' => $now,
            ])->save();

            $changes = ['workflow_status' => $next, 'lock_version' => $obligation->lock_version + 1];
            if ($next === WorkflowStatus::UnderReview) {
                $following = ReviewStep::query()->where('submission_id', $submission->id)->where('step_no', $step->step_no + 1)->firstOrFail();
                $following->forceFill(['status' => 'pending', 'due_at' => $this->calendar->addWorkingDays($now->toDateTimeImmutable(), $cycle->review_sla_working_days)])->save();
                $submission->forceFill(['status' => 'under_review'])->save();
                $changes += ['review_due_at' => $following->due_at, 'current_reviewer_user_id' => $following->reviewer_user_id];
            } else {
                ReviewStep::query()->where('submission_id', $submission->id)->where('status', 'waiting')->update(['status' => 'skipped']);
                $submission->forceFill(['status' => $next->value, 'decided_at' => $now])->save();
                $changes += ['review_due_at' => null, 'current_reviewer_user_id' => null, 'decided_at' => $now];
            }

            if ($next === WorkflowStatus::Returned) {
                $changes += $this->openRevision($obligation, $submission, $cycle, $now);
            }
            if ($decision->requiresReason()) {
                $this->addComment($obligation, $submission->id, $actor, (string) $reason, null, 'submitter', $now);
            }
            $obligation->forceFill($changes)->save();

            $this->audit->record('review_decided', $actor, 'reporting.submission', $submission->id, $obligation->organization_id, [
                'decision' => $decision->value, 'step_no' => $step->step_no, 'workflow_status' => $next->value, 'reference_no' => $submission->reference_no,
            ], $reason, $obligation->id);

            if ($next === WorkflowStatus::Approved) {
                $this->outbox->store(new ReportApprovedV1(
                    eventId: Str::uuid7()->toString(), occurredAt: $now->toIso8601String(), obligationId: $obligation->id,
                    submissionId: $submission->id, versionNo: $submission->version_no, referenceNo: $submission->reference_no,
                    organizationId: $obligation->organization_id, reportTypeId: $obligation->report_type_id, cycleId: $obligation->cycle_id,
                ));
                $this->artifacts->request('submission', $submission->id, 'approved');
            }
            if ($next !== WorkflowStatus::UnderReview) {
                $this->notifications->publish('workflow', $next->value, "decision:{$submission->id}:{$next->value}",
                    array_values(array_filter([(int) $submission->submitted_by, (int) $obligation->preparer_user_id, (int) $obligation->accountable_user_id])),
                    ['period' => $cycle->period_label_en, 'period_am' => $cycle->period_label_am, 'reference' => $submission->reference_no, 'type' => $type->name_en, 'type_am' => $type->name_am],
                    $obligation->id);
            }

            return $submission;
        });
    }

    /**
     * Marks a submitted version as under review when a person who can decide
     * its open step takes it up. Does nothing for anyone else, or twice.
     */
    public function begin(ActorContext $actor, string $submissionId): void
    {
        if ($this->settings->isPaused()) {
            return;
        }
        DB::transaction(function () use ($actor, $submissionId): void {
            $submission = Submission::query()->find($submissionId);
            $obligation = $submission === null ? null : Obligation::query()->lockForUpdate()->find($submission->obligation_id);
            if ($obligation === null || $obligation->workflow_status !== WorkflowStatus::Submitted || $obligation->latest_submission_id !== $submissionId) {
                return;
            }
            $step = ReviewStep::query()->where('submission_id', $submissionId)->where('status', 'pending')->first();
            if ($step === null || ! $actor->can($step->required_permission) || ! ReportingGuard::onReceivingSide($actor, $obligation)) {
                return;
            }
            $submission->forceFill(['status' => 'under_review'])->save();
            $obligation->forceFill(['workflow_status' => WorkflowStatus::UnderReview, 'current_reviewer_user_id' => $step->reviewer_user_id ?? $actor->userId])->save();
            $this->audit->record('review_started', $actor, 'reporting.submission', $submissionId, $obligation->organization_id, ['step_no' => $step->step_no], obligationId: $obligation->id);
        });
    }

    /** Hands an open review step to another person (delegation or reassignment), keeping the history. */
    public function reassign(ActorContext $actor, string $stepId, int $toUserId, string $reason, string $kind = 'reassignment'): ReviewStep
    {
        $this->settings->assertWritable();
        if (trim($reason) === '') {
            throw new WorkflowViolation('reason_required');
        }

        return DB::transaction(function () use ($actor, $stepId, $toUserId, $reason, $kind): ReviewStep {
            $step = ReviewStep::query()->lockForUpdate()->findOrFail($stepId);
            $obligation = Obligation::query()->lockForUpdate()->findOrFail($step->obligation_id);
            if (! in_array($step->status, ['pending', 'waiting'], true)) {
                throw new WorkflowViolation('step_already_decided');
            }
            // The current reviewer may delegate; anyone else needs the step's permission on the receiving side.
            if ((int) $step->reviewer_user_id !== $actor->userId) {
                ReportingGuard::requireReceivingSide($actor, $obligation, $step->required_permission);
            }
            // The new reviewer must be able to decide the step for the receiving institution.
            $target = $this->access->contextFor($toUserId);
            if (! $target->can($step->required_permission) || ! ($target->unrestricted || $target->reaches($obligation->receiving_organization_id))) {
                throw new WorkflowViolation('reviewer_not_eligible');
            }

            ReviewAssignment::query()->create([
                'review_step_id' => $step->id, 'kind' => $kind === 'delegation' ? 'delegation' : 'reassignment',
                'from_user_id' => $step->reviewer_user_id, 'to_user_id' => $toUserId, 'reason' => trim($reason),
                'assigned_by' => $actor->userId, 'created_at' => CarbonImmutable::now('UTC'),
            ]);
            $step->forceFill(['reviewer_user_id' => $toUserId])->save();
            if ($step->status === 'pending') {
                $obligation->forceFill(['current_reviewer_user_id' => $toUserId])->save();
            }
            $this->audit->record('review_reassigned', $actor, 'reporting.review_step', $step->id, $obligation->organization_id,
                ['to_user_id' => $toUserId, 'kind' => $kind, 'step_no' => $step->step_no], $reason, $obligation->id);

            return $step;
        });
    }

    /**
     * A comment on a report. Reviewers may write internal notes, which the
     * reporting side never sees; the reporting side writes only visible ones.
     */
    public function comment(ActorContext $actor, string $obligationId, string $body, ?string $sectionKey, string $visibility): Comment
    {
        $this->settings->assertWritable();
        $obligation = Obligation::query()->findOrFail($obligationId);
        ReportingGuard::requireView($actor, $obligation);
        $reviewer = ReportingGuard::onReceivingSide($actor, $obligation) && ($actor->can(ReportingRoles::REVIEW) || $actor->can(ReportingRoles::APPROVE));
        if ($visibility === 'internal' && ! $reviewer) {
            throw new ReportingAccessDenied(ReportingRoles::REVIEW);
        }
        if (! $reviewer && ! ($actor->can(ReportingRoles::PREPARE) || $actor->can(ReportingRoles::SUBMIT))) {
            throw new ReportingAccessDenied(ReportingRoles::PREPARE);
        }

        return $this->addComment($obligation, $obligation->latest_submission_id, $actor, $body, $sectionKey, $visibility === 'internal' ? 'internal' : 'submitter', CarbonImmutable::now('UTC'));
    }

    /** Reopens a decided report: a new editable version, the decided versions untouched. Needs its own permission and a reason. */
    public function reopen(ActorContext $actor, string $obligationId, string $reason): Obligation
    {
        $this->settings->assertWritable();

        return DB::transaction(function () use ($actor, $obligationId, $reason): Obligation {
            $obligation = Obligation::query()->lockForUpdate()->findOrFail($obligationId);
            ReportingGuard::requireReceivingSide($actor, $obligation, ReportingRoles::REOPEN);
            WorkflowRules::assertReopenable($obligation->workflow_status, $reason);
            $submission = Submission::query()->findOrFail($obligation->latest_submission_id);
            $cycle = Cycle::query()->findOrFail($obligation->cycle_id);
            $now = CarbonImmutable::now('UTC');
            $before = $obligation->workflow_status->value;

            $obligation->forceFill([
                'workflow_status' => WorkflowStatus::Returned, 'decided_at' => null, 'lock_version' => $obligation->lock_version + 1,
                ...$this->openRevision($obligation, $submission, $cycle, $now),
            ])->save();
            $this->addComment($obligation, $submission->id, $actor, $reason, null, 'submitter', $now);
            $this->audit->record('reopened', $actor, 'reporting.obligation', $obligation->id, $obligation->organization_id,
                ['from_status' => $before, 'reopened_version' => $submission->version_no], $reason, $obligation->id);

            return $obligation;
        });
    }

    /**
     * Starts the next editable version from the returned one: same content,
     * same files, a revision deadline of its own.
     *
     * @return array<string, mixed> changes for the obligation
     */
    private function openRevision(Obligation $obligation, Submission $submission, Cycle $cycle, CarbonImmutable $now): array
    {
        $next = $obligation->current_version + 1;
        Draft::query()->create([
            'obligation_id' => $obligation->id, 'version_no' => $next, 'mode' => $submission->mode,
            'content' => $submission->content ?? [], 'summary' => $submission->summary, 'metadata' => $submission->metadata ?? [],
            'is_nil' => $submission->is_nil, 'nil_reason' => $submission->nil_reason, 'completeness_declared' => false,
        ]);
        foreach (Attachment::query()->where('submission_id', $submission->id)->get() as $file) {
            // New rows pointing at the same stored file: the submitted version keeps its own records.
            Attachment::query()->create([...$file->only(['obligation_id', 'kind', 'requirement_key', 'original_name', 'disk', 'path', 'mime', 'size', 'sha256', 'scan_status', 'uploaded_by']), 'version_no' => $next]);
        }

        return ['current_version' => $next, 'revision_due_at' => $now->addDays($cycle->revision_days)];
    }

    private function addComment(Obligation $obligation, ?string $submissionId, ActorContext $actor, string $body, ?string $sectionKey, string $visibility, CarbonImmutable $now): Comment
    {
        $body = trim(strip_tags($body));
        if ($body === '') {
            throw new WorkflowViolation('reason_required');
        }

        return Comment::query()->create([
            'obligation_id' => $obligation->id, 'submission_id' => $submissionId, 'section_key' => $sectionKey, 'visibility' => $visibility,
            'body' => Str::limit($body, 5000, ''), 'author_user_id' => $actor->userId, 'author_name' => $actor->name, 'created_at' => $now,
        ]);
    }
}
