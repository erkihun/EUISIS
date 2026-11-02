<?php

declare(strict_types=1);

namespace App\Enums;

/** How the person found in a supervising position holds it (ADR 0025). */
enum OccupantBasis: string
{
    /** An assignment in force on the date. */
    case Substantive = 'substantive';

    /** An acting appointment in force on the date; it outranks the substantive holder while it runs. */
    case Acting = 'acting';

    public function label(): string
    {
        return (string) __('reporting-lines.occupant_bases.'.$this->value);
    }
}
