<?php

declare(strict_types=1);

namespace App\Enums;

enum GrievanceStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case RequirementIncomplete = 'requirement_incomplete';
    case RequirementFulfilled = 'requirement_fulfilled';
    case InProgress = 'in_progress';
    case ResponseDrafted = 'response_drafted';
    case ResponseCompiled = 'response_compiled';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Closed = 'closed';
    case Escalated = 'escalated';
    case TribunalReferred = 'tribunal_referred';
    case Withdrawn = 'withdrawn';

    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Withdrawn, self::TribunalReferred], true);
    }

    public function canEscalate(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::InProgress, self::RequirementFulfilled], true);
    }
}
