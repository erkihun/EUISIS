<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Enums;

/**
 * Seat on one case. Committee seats follow Art. 5 (chair and one member
 * appointed by the head, two elected members, one HR secretary); an
 * investigator is appointed for the Art. 13 expedited inquiry.
 */
enum DisciplinaryCaseRole: string
{
    case Chairperson = 'chairperson';
    case Member = 'member';
    case Secretary = 'secretary';
    case Investigator = 'investigator';

    public function votes(): bool
    {
        return $this !== self::Investigator;
    }
}
