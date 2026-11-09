<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\Carbon;

/**
 * Thin convenience wrapper around CalendarService.
 *
 * Exposes the display methods under the names used in tests and controllers.
 */
class LocalizedDateService
{
    public function __construct(
        private readonly CalendarService $calendar,
    ) {}

    /**
     * Format a date for display in the given (or current) locale.
     * Returns null when $date is null/empty.
     */
    public function displayDate(Carbon|string|null $date, ?string $locale = null): ?string
    {
        return $this->calendar->formatDate($date, $locale);
    }

    /**
     * Format a datetime for display in the given (or current) locale.
     * Returns null when $date is null/empty.
     */
    public function displayDateTime(Carbon|string|null $date, ?string $locale = null): ?string
    {
        return $this->calendar->formatDateTime($date, $locale);
    }

    /**
     * Return 'ethiopian' or 'gregorian' for the given locale string.
     */
    public function calendarSystemForLocale(string $locale): string
    {
        return $this->calendar->calendarSystemForLocale($locale)->value;
    }
}
