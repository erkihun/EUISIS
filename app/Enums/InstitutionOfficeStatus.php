<?php

declare(strict_types=1);

namespace App\Enums;

enum InstitutionOfficeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return __('institution-offices.statuses.'.$this->value);
    }
}
