<?php

declare(strict_types=1);

namespace Modules\Discipline\Contracts\Data;

/** Minor (Art. 10) or serious (Art. 11) disciplinary violation. */
enum DisciplinarySeverity: string
{
    case Minor = 'minor';
    case Serious = 'serious';
}
