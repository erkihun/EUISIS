<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Calendar\EthiopianCalendarService;

/**
 * Static façade for EthiopianCalendarService.
 *
 * Provides a simple static API for contexts that cannot use dependency
 * injection (e.g. Blade views, quick scripts, tests).
 *
 * All conversion logic lives in EthiopianCalendarService; this class
 * only proxies calls through the Laravel container.
 */
class EthiopianCalendar
{
    /** @return array{year: int, month: int, day: int} */
    public static function toEthiopian(\DateTimeInterface $gregorian): array
    {
        /** @var EthiopianCalendarService $service */
        $service = app(EthiopianCalendarService::class);

        return $service->gregorianToEthiopian(
            (int) $gregorian->format('Y'),
            (int) $gregorian->format('n'),
            (int) $gregorian->format('j'),
        );
    }

    /**
     * Format a Gregorian date as an Ethiopian date string.
     *
     * Supported tokens: dd, mm, yyyy, MMM
     * Default: 'dd/mm/yyyy'
     */
    public static function format(\DateTimeInterface $date, string $pattern = 'dd/mm/yyyy'): string
    {
        /** @var EthiopianCalendarService $service */
        $service = app(EthiopianCalendarService::class);

        $eth = $service->gregorianToEthiopian(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date->format('j'),
        );

        $monthNames = $service->ethiopianMonthNames('am');
        $monthName = $monthNames[$eth['month']] ?? (string) $eth['month'];

        $day = str_pad((string) $eth['day'], 2, '0', STR_PAD_LEFT);
        $month = str_pad((string) $eth['month'], 2, '0', STR_PAD_LEFT);
        $year = (string) $eth['year'];

        return str_replace(
            ['dd', 'mm', 'yyyy', 'MMM'],
            [$day, $month, $year, $monthName],
            $pattern,
        );
    }
}
