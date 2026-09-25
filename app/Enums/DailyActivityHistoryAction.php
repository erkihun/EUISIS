<?php

declare(strict_types=1);

namespace App\Enums;

enum DailyActivityHistoryAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Submitted = 'submitted';
    case Resubmitted = 'resubmitted';
    case ReviewStarted = 'review_started';
    case Returned = 'returned';
    case Approved = 'approved';
    case Reopened = 'reopened';
    case AttachmentUploaded = 'attachment_uploaded';
    case AttachmentDeleted = 'attachment_deleted';
}
