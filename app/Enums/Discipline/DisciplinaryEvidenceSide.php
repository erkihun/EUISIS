<?php

declare(strict_types=1);

namespace App\Enums\Discipline;

/** Who produced the evidence or called the witness (Arts. 15(1)(e), 20(2)(c), 22(2)). */
enum DisciplinaryEvidenceSide: string
{
    case Institution = 'institution';
    case Accused = 'accused';
    case Committee = 'committee';
}
