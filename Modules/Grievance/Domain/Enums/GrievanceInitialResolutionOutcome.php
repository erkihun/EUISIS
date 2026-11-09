<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * Outcome recorded when the initial written submission + discussion ends
 * (Regulation 201/2018 Art. 33(1)). Only an unresolved or unanswered
 * attempt starts the 10-working-day formal-filing window; a resolved
 * matter never becomes a formal grievance.
 */
enum GrievanceInitialResolutionOutcome: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
    case NoResponse = 'no_response';

    public function startsFilingClock(): bool
    {
        return $this === self::Unresolved || $this === self::NoResponse;
    }
}
