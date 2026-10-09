<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Enums\CalendarSystem;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Locale-aware calendar service.
 *
 * Rules:
 *  - locale `am` → Ethiopian calendar for display/input
 *  - locale `en` → Gregorian calendar for display/input
 *  - Database ALWAYS stores Gregorian ISO (YYYY-MM-DD).
 */
class CalendarService
{
    public function __construct(
        private readonly EthiopianCalendarService $ethiopian,
    ) {}

    // ─── Calendar system for locale ──────────────────────────────────────────

    public function calendarSystemForLocale(string $locale): CalendarSystem
    {
        return match ($locale) {
            'am'    => CalendarSystem::Ethiopian,
            default => CalendarSystem::Gregorian,
        };
    }

    // ─── Display formatting ───────────────────────────────────────────────────

    /**
     * Format a date (Carbon, ISO string, or null) for display in the given locale.
     * Returns null if date is null/empty.
     */
    public function formatDate(Carbon|string|null $date, ?string $locale = null): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $carbon  = $this->toCarbonOrNull($date);
        if ($carbon === null) {
            return null;
        }

        $locale ??= app()->getLocale();

        if ($this->calendarSystemForLocale($locale) === CalendarSystem::Ethiopian) {
            return $this->ethiopian->formatGregorianDateAsEthiopian($carbon, $locale);
        }

        return $carbon->format('F j, Y');
    }

    /**
     * Format a datetime for display.
     */
    public function formatDateTime(Carbon|string|null $dateTime, ?string $locale = null): ?string
    {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        $carbon = $this->toCarbonOrNull($dateTime);
        if ($carbon === null) {
            return null;
        }

        $locale ??= app()->getLocale();
        $timePart = $carbon->format('H:i');

        if ($this->calendarSystemForLocale($locale) === CalendarSystem::Ethiopian) {
            $datePart = $this->ethiopian->formatGregorianDateAsEthiopian($carbon, $locale);
            return "{$datePart} {$timePart}";
        }

        return $carbon->format('F j, Y H:i');
    }

    // ─── Parsing: localized input → Gregorian ────────────────────────────────

    /**
     * Parse a date string that may be either Gregorian ISO (YYYY-MM-DD)
     * or an Ethiopian date (YYYY-MM-DD with calendar_system=ethiopian).
     *
     * Always returns a Gregorian Carbon date.
     *
     * @throws InvalidArgumentException on unparseable input
     */
    public function parseToGregorian(string $input, CalendarSystem $sourceCalendar = CalendarSystem::Gregorian): Carbon
    {
        // Gregorian ISO: YYYY-MM-DD (frontend always submits this)
        if ($sourceCalendar === CalendarSystem::Gregorian) {
            if (! $this->isValidGregorianIso($input)) {
                throw new InvalidArgumentException("Invalid Gregorian date: {$input}");
            }
            return Carbon::parse($input);
        }

        // Ethiopian: YYYY-MM-DD → parse as Ethiopian then convert
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $m)) {
            return $this->ethiopian->ethiopianToGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        throw new InvalidArgumentException("Cannot parse Ethiopian date: {$input}");
    }

    // ─── Rich date payload for frontend ──────────────────────────────────────

    /**
     * Returns an array with both machine-readable Gregorian value
     * and locale-aware display value.
     *
     * @return array{date_value: string|null, date_display: string|null, calendar_system: string}
     */
    public function toDatePayload(Carbon|string|null $date, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $carbon = $this->toCarbonOrNull($date);

        return [
            'date_value'      => $carbon?->toDateString(),
            'date_display'    => $this->formatDate($carbon, $locale),
            'calendar_system' => $this->calendarSystemForLocale($locale)->value,
        ];
    }

    /**
     * @return array{date_value: string|null, date_display: string|null, calendar_system: string}
     */
    public function toDateTimePayload(Carbon|string|null $dateTime, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $carbon = $this->toCarbonOrNull($dateTime);

        return [
            'date_value'      => $carbon?->toISOString(),
            'date_display'    => $this->formatDateTime($carbon, $locale),
            'calendar_system' => $this->calendarSystemForLocale($locale)->value,
        ];
    }

    // ─── Validation helpers ───────────────────────────────────────────────────

    public function isValidGregorianIso(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }

    public function isValidEthiopianIso(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }
        return $this->ethiopian->isValidEthiopianDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    // ─── Internal ────────────────────────────────────────────────────────────

    private function toCarbonOrNull(Carbon|string|null $date): ?Carbon
    {
        if ($date === null || $date === '') {
            return null;
        }
        if ($date instanceof Carbon) {
            return $date;
        }
        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }
}
