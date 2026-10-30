<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Case-level grievance status (docs/grievance-management.md §4). The detailed
 * position of a case lives on its current stage (GrievanceStageStatus); this
 * is the summary shown in lists and to the complainant.
 *
 * The "legacy" cases come from the first grievance module. The 2026_09_28
 * backfill migration maps existing rows to the current vocabulary; the cases
 * stay so an un-migrated row still hydrates instead of throwing.
 */
enum GrievanceStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case IntakeReview = 'intake_review';
    case ReturnedForCorrection = 'returned_for_correction';
    case RejectedAtIntake = 'rejected_at_intake';
    case UnderReview = 'under_review';
    case AwaitingInformation = 'awaiting_information';
    case HearingScheduled = 'hearing_scheduled';
    case DecisionDrafting = 'decision_drafting';
    case PendingApproval = 'pending_approval';
    case DecisionIssued = 'decision_issued';
    case Appealed = 'appealed';
    case ReferredExternal = 'referred_external';
    case WithdrawRequested = 'withdraw_requested';
    case Withdrawn = 'withdrawn';
    case Closed = 'closed';

    // ── Legacy (first grievance module) ──────────────────────────────────────
    case RequirementIncomplete = 'requirement_incomplete';
    case RequirementFulfilled = 'requirement_fulfilled';
    case InProgress = 'in_progress';
    case ResponseDrafted = 'response_drafted';
    case ResponseCompiled = 'response_compiled';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Escalated = 'escalated';
    case TribunalReferred = 'tribunal_referred';

    /** Terminal: no handler work remains (reopen is a separate, permissioned action). */
    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Withdrawn, self::RejectedAtIntake, self::TribunalReferred], true);
    }

    /** A handler is working the case at a stage. */
    public function isInProgress(): bool
    {
        return in_array($this, [
            self::UnderReview, self::AwaitingInformation, self::HearingScheduled,
            self::DecisionDrafting, self::PendingApproval, self::Appealed,
            self::WithdrawRequested,
            self::RequirementFulfilled, self::InProgress, self::ResponseDrafted,
            self::ResponseCompiled, self::AwaitingApproval, self::Escalated,
        ], true);
    }

    /** The complainant may still edit (draft, or returned at intake for correction). */
    public function isEditableByComplainant(): bool
    {
        return in_array($this, [self::Draft, self::ReturnedForCorrection, self::RequirementIncomplete], true);
    }

    public function canEscalate(): bool
    {
        return $this->isInProgress() && $this !== self::WithdrawRequested;
    }

    /** Statuses offered in filters and the UI (legacy values are hidden). */
    public static function current(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s): bool => ! $s->isLegacy()));
    }

    public function isLegacy(): bool
    {
        return in_array($this, [
            self::RequirementIncomplete, self::RequirementFulfilled, self::InProgress,
            self::ResponseDrafted, self::ResponseCompiled, self::AwaitingApproval,
            self::Approved, self::Rejected, self::Escalated, self::TribunalReferred,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::current(), 'value');
    }
}
