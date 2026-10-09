<?php

declare(strict_types=1);

namespace App\Enums;

enum RelationshipStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('relationships.statuses.'.$this->value);
    }
}
