<?php

declare(strict_types=1);

namespace App\Enums;

/** Physical availability of a cafeteria location — never a subsidy rule. */
enum CafeteriaOperationalStatus: string
{
    case Open = 'open';
    case TemporarilyClosed = 'temporarily_closed';
    case Closed = 'closed';
}
