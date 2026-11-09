<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * Language of an official letter.
 */
enum GrievanceLetterLanguage: string
{
    case Am = 'am';
    case En = 'en';
    case Bilingual = 'bilingual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
