<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts\Data;

use App\Models\User;

/**
 * Who an employee's line manager is on a date and which rule said so
 * (ADR 0025). `basis` is one of the BASIS_* constants; workflows record it
 * with the request so the history shows whether a unit head, a published
 * position profile, an acting appointment or a reviewer assignment named the
 * manager. A null user with a basis of NONE is "nobody": the request waits.
 */
final class ManagerResolution
{
    /** The structural resolution (ADR 0025): the head of the unit / parent unit / organization. */
    public const BASIS_UNIT_HEAD = 'unit_head';

    /** The structural resolution through a published position profile's "reports to". */
    public const BASIS_EXPLICIT_PROFILE = 'explicit_profile';

    /** The structural resolution landed on an acting appointment. */
    public const BASIS_ACTING_APPOINTMENT = 'acting_appointment';

    /** The explicit daily-work reviewer assignment (the pre-ADR-0025 rule, and the fallback). */
    public const BASIS_REVIEWER_ASSIGNMENT = 'reviewer_assignment';

    public const BASIS_NONE = 'none';

    public function __construct(
        public readonly ?User $user,
        public readonly string $basis,
        /** The structural outcome (ReportingOutcome value) when the structure was consulted, else null. */
        public readonly ?string $structuralOutcome = null,
    ) {}

    public static function nobody(?string $structuralOutcome = null): self
    {
        return new self(null, self::BASIS_NONE, $structuralOutcome);
    }

    public function resolved(): bool
    {
        return $this->user !== null;
    }
}
