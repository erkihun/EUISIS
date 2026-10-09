<?php

declare(strict_types=1);

namespace App\Enums;

enum CalendarSystem: string
{
    case Gregorian = 'gregorian';
    case Ethiopian = 'ethiopian';

    public function label(string $locale = 'en'): string
    {
        return match ($this) {
            self::Gregorian => $locale === 'am' ? 'ጎርጎሮሳዊ ቀን አቆጣጠር' : 'Gregorian Calendar',
            self::Ethiopian => $locale === 'am' ? 'ኢትዮጵያዊ ቀን አቆጣጠር' : 'Ethiopian Calendar',
        };
    }
}
