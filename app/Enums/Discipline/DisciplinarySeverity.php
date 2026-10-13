<?php

declare(strict_types=1);

namespace App\Enums\Discipline;

/** Minor (Art. 10) or serious (Art. 11) disciplinary violation. */
enum DisciplinarySeverity: string
{
    case Minor = 'minor';
    case Serious = 'serious';
}
