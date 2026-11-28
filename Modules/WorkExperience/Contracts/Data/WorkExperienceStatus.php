<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Contracts\Data;

/** Where a request for a work experience certificate stands (ADR 0040). */
enum WorkExperienceStatus: string
{
    /** Waiting for the office to look at it. */
    case Submitted = 'submitted';
    /** Accepted: the certificate is numbered and ready to be printed, signed and stamped. */
    case Accepted = 'accepted';
    /** Sent back with a reason; the employee may ask again. */
    case Returned = 'returned';
    /** The printed, signed and stamped certificate was sent to the employee. */
    case Sent = 'sent';
    /** Withdrawn by the employee before it was decided. */
    case Cancelled = 'cancelled';

    public function hasCertificate(): bool
    {
        return $this === self::Accepted || $this === self::Sent;
    }
}
