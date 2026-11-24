<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/**
 * Where an obligation stands in the workflow. Timeliness, reviewer timeliness
 * and applicability are separate facts and never folded into this value.
 */
enum WorkflowStatus: string
{
    case NotStarted = 'not_started';
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Returned = 'returned';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** A submission is with the receiving institution and awaits a decision. */
    public function awaitsDecision(): bool
    {
        return $this === self::Submitted || $this === self::UnderReview;
    }

    /** The reporting side may edit a working draft. */
    public function isEditable(): bool
    {
        return $this === self::NotStarted || $this === self::Draft || $this === self::Returned;
    }

    public function isFinal(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
