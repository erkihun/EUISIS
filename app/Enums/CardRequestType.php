<?php

declare(strict_types=1);

namespace App\Enums;

enum CardRequestType: string
{
    case New = 'new';
    case Renewal = 'renewal';
    case Replacement = 'replacement';
    case Lost = 'lost';
    case Damaged = 'damaged';
    case Correction = 'correction';
}
