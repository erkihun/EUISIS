<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Enums;

/**
 * Regulation 201/2018: Art. 13 expedited inquiry by appointed investigator(s)
 * for minor violations; Art. 14 formal committee investigation for violations
 * punishable under Art. 11.
 */
enum DisciplinaryTrack: string
{
    case Expedited = 'expedited';
    case Formal = 'formal';
}
