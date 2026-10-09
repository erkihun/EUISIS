<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkflowStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Escalated = 'escalated';
    case Cancelled = 'cancelled';
}
