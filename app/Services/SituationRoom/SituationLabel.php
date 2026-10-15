<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

/**
 * How a label travels to the page: a translation key the page translates, or
 * a bilingual name pair from master data (organization, unit, category). The
 * page picks the visitor's language; no raw enum value is ever shown.
 */
final class SituationLabel
{
    /** @return array{key: string} */
    public static function key(string $translationKey): array
    {
        return ['key' => $translationKey];
    }

    /** @return array{en: ?string, am: ?string} */
    public static function names(?string $en, ?string $am): array
    {
        return ['en' => $en, 'am' => $am];
    }
}
