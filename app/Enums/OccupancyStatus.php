<?php

declare(strict_types=1);

namespace App\Enums;

enum OccupancyStatus: string
{
    case Active = 'active';
    case Released = 'released';

    public function label(): string
    {
        return (string) __('enums.occupancy_status.'.$this->value);
    }
}
