<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Review state of a submitted client feedback entry.
 *
 * `hidden` removes a comment from reports and employee-facing views without
 * deleting it — abusive or personally identifying comments are suppressed but
 * remain auditable. Hidden entries still count toward rating aggregates unless
 * a report explicitly excludes them.
 */
enum ServiceFeedbackStatus: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';
    case Resolved = 'resolved';
    case Hidden = 'hidden';

    public function isVisibleToEmployee(): bool
    {
        return $this !== self::Hidden;
    }
}
