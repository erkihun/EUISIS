<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
    case Transferred = 'transferred';
    case Retired = 'retired';
    case Terminated = 'terminated';
    case Deceased = 'deceased';
}
