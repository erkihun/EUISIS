<?php

declare(strict_types=1);

namespace App\Enums;

/** Where an executive action stands. Done and Cancelled are final; nothing is ever deleted. */
enum SituationActionStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Open->value, self::InProgress->value];
    }
}
