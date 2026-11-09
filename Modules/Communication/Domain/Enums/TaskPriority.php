<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Enums;

/** Priority of a task made from a message. */
enum TaskPriority: string
{
    case Low = 'LOW';
    case Normal = 'NORMAL';
    case High = 'HIGH';
    case Urgent = 'URGENT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
