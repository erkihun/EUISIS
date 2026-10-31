<?php

declare(strict_types=1);

namespace Modules\FieldWork\Domain\Enums;

enum FieldWorkHistoryAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Completed = 'completed';
}
