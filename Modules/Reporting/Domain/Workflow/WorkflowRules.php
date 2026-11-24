<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Workflow;

use DateTimeImmutable;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Enums\LatePolicy;
use Modules\Reporting\Domain\Enums\ReviewDecision;
use Modules\Reporting\Domain\Enums\WorkflowStatus;

/**
 * The workflow's transitions and guards:
 *
 *   Not started → Draft → Submitted → Under review → Approved / Returned / Rejected
 *   Returned → (new editable version) → Submitted …
 *   Approved / Rejected → Returned, only by reopening with a reason.
 *
 * Pure rules: callers pass facts in and get a status back or a WorkflowViolation.
 */
final class WorkflowRules
{
    public static function assertEditable(WorkflowStatus $status, Applicability $applicability, ?DateTimeImmutable $opensAt, DateTimeImmutable $now): void
    {
        if ($applicability !== Applicability::Required) {
            throw new WorkflowViolation('not_applicable');
        }
        if (! $status->isEditable()) {
            throw new WorkflowViolation('not_editable', ['status' => $status->value]);
        }
        if ($opensAt !== null && $now < $opensAt) {
            throw new WorkflowViolation('not_open_yet');
        }
    }

    /**
     * May a version be submitted now? Returns whether it is late.
     * A resubmission after a return is never refused by the late policy: its
     * own revision deadline is tracked separately.
     */
    public static function assertSubmittable(
        WorkflowStatus $status,
        Applicability $applicability,
        ?DateTimeImmutable $opensAt,
        DateTimeImmutable $effectiveDueAt,
        DateTimeImmutable $now,
        LatePolicy $latePolicy,
        bool $isFirstSubmission,
        ?string $lateReason,
    ): bool {
        self::assertEditable($status, $applicability, $opensAt, $now);
        $late = $now > $effectiveDueAt;
        if ($late && $isFirstSubmission) {
            if ($latePolicy === LatePolicy::Block) {
                throw new WorkflowViolation('late_blocked');
            }
            if ($latePolicy === LatePolicy::AcceptWithReason && trim((string) $lateReason) === '') {
                throw new WorkflowViolation('late_reason_required');
            }
        }

        return $late;
    }

    /**
     * The status after a reviewer's decision at one step.
     *
     * @param  bool  $isLastStep  approving a step that is not the last moves to the next reviewer
     */
    public static function afterDecision(WorkflowStatus $status, ReviewDecision $decision, bool $isLastStep, ?string $reason, bool $reviewerIsSubmitter, bool $selfApprovalAllowed): WorkflowStatus
    {
        if (! $status->awaitsDecision()) {
            throw new WorkflowViolation('not_awaiting_decision', ['status' => $status->value]);
        }
        if ($reviewerIsSubmitter && ! $selfApprovalAllowed) {
            throw new WorkflowViolation('self_review_forbidden');
        }
        if ($decision->requiresReason() && trim((string) $reason) === '') {
            throw new WorkflowViolation('reason_required');
        }

        return match ($decision) {
            ReviewDecision::Approve => $isLastStep ? WorkflowStatus::Approved : WorkflowStatus::UnderReview,
            ReviewDecision::Return => WorkflowStatus::Returned,
            ReviewDecision::Reject => WorkflowStatus::Rejected,
        };
    }

    public static function assertReopenable(WorkflowStatus $status, ?string $reason): void
    {
        if (! $status->isFinal()) {
            throw new WorkflowViolation('not_final', ['status' => $status->value]);
        }
        if (trim((string) $reason) === '') {
            throw new WorkflowViolation('reason_required');
        }
    }
}
