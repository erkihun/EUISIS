<?php

namespace Modules\Court\Application\Support;

use Carbon\Carbon;
use DateTimeInterface;

class EthiopianDate
{
    /**
     * Ethiopian month names (Amharic).
     */
    protected static array $months = [
        'መስከረም',
        'ጥቅምት',
        'ኅዳር',
        'ታህሳስ',
        'ጥር',
        'የካቲት',
        'መጋቢት',
        'ሚያዝያ',
        'ግንቦት',
        'ሰኔ',
        'ሐምሌ',
        'ነሐሴ',
        'ጳጉሜን',
    ];

    public static function format(
        DateTimeInterface|string|null $value,
        bool $withTime = false,
        string $fallback = '',
        string $timeFormat = 'h:i A',
        string $separator = ' '
    ): string {
        $date = static::toCarbon($value);
        if (!$date) {
            return $fallback;
        }

        $ethiopian = static::toEthiopianParts($date);
        if (!$ethiopian) {
            return $fallback;
        }

        $month = static::$months[$ethiopian['month'] - 1] ?? (string) $ethiopian['month'];
        $dateString = sprintf('%s-%d-%04d ዓ.ም', $month, $ethiopian['day'], $ethiopian['year']);

        if (!$withTime) {
            return $dateString;
        }

        $timeString = static::formatEthiopianTime($date, $timeFormat);

        return trim($dateString . ($separator ?? ' ') . $timeString);
    }

    public static function formatDate(DateTimeInterface|string|null $value, string $fallback = ''): string
    {
        return static::format($value, false, $fallback);
    }

    /**
     * Locale-aware format: Ethiopian calendar when locale is 'am', Gregorian otherwise.
     */
    public static function smartFormat(
        DateTimeInterface|string|null $value,
        bool $withTime = false,
        string $fallback = '—',
        string $timeFormat = 'h:i A',
        string $gregFormat = 'd M Y'
    ): string {
        if (\Illuminate\Support\Facades\App::getLocale() === 'am') {
            return static::format($value, $withTime, $fallback, $timeFormat);
        }

        $date = static::toCarbon($value);
        if (!$date) {
            return $fallback;
        }

        $out = $date->format($gregFormat);
        if ($withTime) {
            $out .= ' ' . $date->format('h:i A');
        }

        return $out;
    }

    /**
     * Locale-aware relative time: "X days ago" style, always Gregorian-relative.
     * Returns Ethiopian absolute date when locale is 'am'.
     */
    public static function smartRelative(
        DateTimeInterface|string|null $value,
        string $fallback = '—'
    ): string {
        $date = static::toCarbon($value);
        if (!$date) {
            return $fallback;
        }

        if (\Illuminate\Support\Facades\App::getLocale() === 'am') {
            return static::format($value, false, $fallback);
        }

        return $date->diffForHumans();
    }

    public static function formatDateTime(
        DateTimeInterface|string|null $value,
        string $fallback = '',
        string $timeFormat = 'h:i A',
        string $separator = ' '
    ): string {
        return static::format($value, true, $fallback, $timeFormat, $separator);
    }

    public static function formatTime(DateTimeInterface|string|null $value, string $fallback = '', string $timeFormat = 'h:i A'): string
    {
        $date = static::toCarbon($value);
        if (!$date) {
            return $fallback;
        }

        return static::formatEthiopianTime($date, $timeFormat);
    }

    /**
     * Replace English meridiem markers with Amharic equivalents.
     */
    protected static function localizeMeridiem(string $timeString): string
    {
        return str_replace(
            ['AM', 'PM', 'am', 'pm'],
            ['ከሰአት በፊት', 'ከሰአት በኋላ', 'ከሰአት በፊት', 'ከሰአት በኋላ'],
            $timeString
        );
    }

    /**
     * Convert to Ethiopian time (12-hour clock starting at 6:00 local) and localize meridiem.
     * Example: 10:17 AM -> 4:17 ከሰአት በፊት
     */
    protected static function formatEthiopianTime(Carbon $date, string $timeFormat): string
    {
        $hour24 = (int) $date->format('G');
        $minute = $date->format('i');

        // Ethiopian clock shifts by 6 hours (starts at 6:00 local)
        $ethiopianHour = ($hour24 + 6) % 12;
        if ($ethiopianHour === 0) {
            $ethiopianHour = 12;
        }

        // Keep the hour zero-padded to two digits (e.g. 04:00, not 4:00).
        $baseTime = sprintf('%02d:%s', $ethiopianHour, $minute);

        // Append meridiem if the requested format expects it
        if (str_contains($timeFormat, 'A') || str_contains($timeFormat, 'a')) {
            $meridiem = $date->format('A');
            $localizedMeridiem = static::localizeMeridiem($meridiem);
            return trim($baseTime . ' ' . $localizedMeridiem);
        }

        return $baseTime;
    }

    protected static function toCarbon(DateTimeInterface|string|null $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            $date = $value->copy();
        } elseif ($value instanceof DateTimeInterface) {
            $date = Carbon::instance($value);
        } elseif (is_string($value) && trim($value) !== '') {
            try {
                $date = Carbon::parse($value);
            } catch (\Throwable $e) {
                return null;
            }
        } else {
            return null;
        }

        try {
            $tz = config('app.timezone');
            if ($tz) {
                $date = $date->clone()->timezone($tz);
            }
        } catch (\Throwable $e) {
            // ignore timezone issues
        }

        return $date;
    }

    /** The first day of the Ethiopian month the date falls in (Tikimt 1 for any day of Tikimt), at midnight. */
    public static function startOfMonth(Carbon $date): Carbon
    {
        $parts = static::toEthiopianParts($date);

        return $parts === null ? $date->copy()->startOfMonth() : $date->copy()->startOfDay()->subDays($parts['day'] - 1);
    }

    protected static function toEthiopianParts(Carbon $date): ?array
    {
        $jdEpoch = 1724220.5;
        $jd = static::gregorianToJD($date);
        $c = floor($jd) + 0.5 - $jdEpoch;
        $year = (int) floor(($c - floor(($c + 366) / 1461)) / 365) + 1;
        if ($year <= 0) {
            $year--;
        }

        $yearStart = static::ethiopianToJD($year, 1, 1);
        $dayOfYear = floor($jd) + 0.5 - $yearStart + 1;
        $month = (int) floor(($dayOfYear - 1) / 30) + 1;
        $day = (int) ($dayOfYear - ($month - 1) * 30);

        if ($month < 1 || $day < 1) {
            return null;
        }

        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
        ];
    }

    protected static function gregorianToJD(Carbon $date): float
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');

        if ($year < 0) {
            $year++;
        }

        if ($month < 3) {
            $month += 12;
            $year--;
        }

        $a = floor($year / 100);
        $b = 2 - $a + floor($a / 4);

        return floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
    }

    protected static function ethiopianToJD(int $year, int $month, int $day): float
    {
        $jdEpoch = 1724220.5;
        if ($year < 0) {
            $year++;
        }

        return $day + ($month - 1) * 30 + ($year - 1) * 365 + floor($year / 4) + $jdEpoch - 1;
    }
}
