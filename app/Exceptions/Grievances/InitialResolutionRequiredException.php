<?php

declare(strict_types=1);

namespace App\Exceptions\Grievances;

use DomainException;

/**
 * Raised when a formal grievance is submitted without a completed,
 * unresolved initial-resolution attempt (Regulation 201/2018 Art. 33(1)).
 */
final class InitialResolutionRequiredException extends DomainException
{
    public static function notFailed(): self
    {
        return new self(__('grievances.errors.initial_resolution_not_failed'));
    }

    public static function missing(): self
    {
        return new self(__('grievances.errors.initial_resolution_required'));
    }
}
