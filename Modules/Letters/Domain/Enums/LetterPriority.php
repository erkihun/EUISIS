<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** Handling priority stamped on a letter (ተራ / አስቸኳይ / እጅግ አስቸኳይ). */
enum LetterPriority: string
{
    case Normal = 'normal';
    case Urgent = 'urgent';
    case VeryUrgent = 'very_urgent';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
