<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Calendar\EthiopianCalendarService;
use Carbon\Carbon;

/**
 * Dates for people (ADR 0011), the same rule as the screens: Amharic shows the
 * Ethiopian calendar, English the Gregorian (ISO) date. Stored and API dates
 * stay canonical Gregorian; this is display only (exports, e-mail).
 */
final class CoreDates
{
    public static function display(mixed $value, string $locale, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
        if ($locale !== 'am') {
            return $withTime ? $date->format('Y-m-d H:i') : $date->toDateString();
        }
        $text = app(EthiopianCalendarService::class)->formatGregorianDateAsEthiopian($date, 'am');

        return $withTime ? $text.' '.$date->format('H:i') : $text;
    }
}
