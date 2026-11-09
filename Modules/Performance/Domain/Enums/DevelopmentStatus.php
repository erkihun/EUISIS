<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Enums;

/**
 * PIP / IDP progress.
 */
enum DevelopmentStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
