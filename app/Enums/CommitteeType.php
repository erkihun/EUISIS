<?php

declare(strict_types=1);

namespace App\Enums;

enum CommitteeType: string
{
    case Grievance = 'grievance';
    case Disciplinary = 'disciplinary';
    case Tribunal = 'tribunal';
}
