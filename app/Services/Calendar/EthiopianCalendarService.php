<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Ethiopian Calendar (Amete Mihret / Era of Mercy) conversion service.
 *
 * Algorithm is based on the Julian Day Number (JDN) approach.
 * The Ethiopian calendar epoch (1 Meskerem 1 = August 29, 8 CE Julian) = JDN 1724221.
 *
 * Verified test vectors:
 *   - Sept 11, 2023 Gregorian = Meskerem 1, 2016 Ethiopian
 *   - Jan 7, 2024 Gregorian   = Tahsas 29, 2016 Ethiopian
 *   - May 26, 2026 Gregorian  = Ginbot 18, 2018 Ethiopian
 */
class EthiopianCalendarService
{
    /** JDN of 1 Meskerem 1 (Ethiopian epoch) */
    private const ETHIOPIAN_EPOCH = 1724221;

    /** @var string[] Ethiopian month names in Amharic (1-indexed, index 0 unused) */
    private const MONTH_NAMES_AM = [
        '',
        'መስከረም', // 1  Meskerem
        'ጥቅምት',  // 2  Tikimt
        'ኅዳር',   // 3  Hidar
        'ታኅሣሥ',  // 4  Tahsas
        'ጥር',    // 5  Tir
        'የካቲት',  // 6  Yekatit
        'መጋቢት',  // 7  Megabit
        'ሚያዝያ',  // 8  Miyazia
        'ግንቦት',  // 9  Ginbot
        'ሰኔ',    // 10 Sene
        'ሐምሌ',   // 11 Hamle
        'ነሐሴ',   // 12 Nehase
        'ጳጉሜ',  // 13 Pagume
    ];

    /** @var string[] Ethiopian month names in English (1-indexed) */
    private const MONTH_NAMES_EN = [
        '',
        'Meskerem',
        'Tikimt',
        'Hidar',
        'Tahsas',
        'Tir',
        'Yekatit',
        'Megabit',
        'Miyazia',
        'Ginbot',
        'Sene',
        'Hamle',
        'Nehase',
        'Pagume',
    ];

    /** @var string[] Amharic weekday names (Sun=0 … Sat=6) */
    private const WEEKDAY_NAMES_AM = ['እሁድ', 'ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'ዓርብ', 'ቅዳሜ'];

    /** @var string[] English weekday names (Sun=0 … Sat=6) */
    private const WEEKDAY_NAMES_EN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    // ─── Gregorian ↔ JDN ────────────────────────────────────────────────────

    public function gregorianToJdn(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day
            + intdiv(153 * $m + 2, 5)
            + 365 * $y
            + intdiv($y, 4)
            - intdiv($y, 100)
            + intdiv($y, 400)
            - 32045;
    }

    /** @return array{year: int, month: int, day: int} */
    public function jdnToGregorian(int $jdn): array
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        return [
            'year'  => 100 * $b + $d - 4800 + intdiv($m, 10),
            'month' => $m + 3 - 12 * intdiv($m, 10),
            'day'   => $e - intdiv(153 * $m + 2, 5) + 1,
        ];
    }

    // ─── Ethiopian ↔ JDN ────────────────────────────────────────────────────

    /** @return array{year: int, month: int, day: int} */
    public function jdnToEthiopian(int $jdn): array
    {
        $diff = $jdn - self::ETHIOPIAN_EPOCH;

        // Handle pre-epoch dates
        if ($diff < 0) {
            // Shift into positive range using a multiple of 1461
            $cycles = (int) ceil(-$diff / 1461);
            $diff  += $cycles * 1461;
            $baseYear = -4 * $cycles;
        } else {
            $baseYear = 0;
        }

        $cycle = intdiv($diff, 1461);
        $r     = $diff % 1461;                             // 0 … 1460

        // year-in-cycle: 0..3  (year 4 is the leap year at r=1095..1460)
        $yearInCycle = intdiv($r, 365) - intdiv($r, 1460);
        $dayOfYear   = $r - $yearInCycle * 365;            // 0 … 364 (or 365 on leap day)

        return [
            'year'  => 4 * $cycle + $yearInCycle + 1 + $baseYear,
            'month' => intdiv($dayOfYear, 30) + 1,
            'day'   => ($dayOfYear % 30) + 1,
        ];
    }

    public function ethiopianToJdn(int $year, int $month, int $day): int
    {
        return self::ETHIOPIAN_EPOCH
            + 1461 * intdiv($year - 1, 4)
            + 365 * (($year - 1) % 4)
            + 30 * ($month - 1)
            + ($day - 1);
    }

    // ─── Public conversion API ───────────────────────────────────────────────

    /** @return array{year: int, month: int, day: int} */
    public function gregorianToEthiopian(int $year, int $month, int $day): array
    {
        return $this->jdnToEthiopian($this->gregorianToJdn($year, $month, $day));
    }

    public function ethiopianToGregorian(int $year, int $month, int $day): Carbon
    {
        $this->assertValidEthiopianDate($year, $month, $day);
        $g = $this->jdnToGregorian($this->ethiopianToJdn($year, $month, $day));

        return Carbon::createFromDate($g['year'], $g['month'], $g['day']);
    }

    // ─── Validation ─────────────────────────────────────────────────────────

    public function isValidEthiopianDate(int $year, int $month, int $day): bool
    {
        if ($month < 1 || $month > 13) {
            return false;
        }
        if ($day < 1) {
            return false;
        }
        if ($month <= 12 && $day > 30) {
            return false;
        }
        if ($month === 13) {
            $maxDay = $this->isEthiopianLeapYear($year) ? 6 : 5;
            if ($day > $maxDay) {
                return false;
            }
        }

        return true;
    }

    /** Ethiopian leap year: year % 4 === 3 */
    public function isEthiopianLeapYear(int $year): bool
    {
        return $year % 4 === 3;
    }

    // ─── Formatting & names ──────────────────────────────────────────────────

    /** @return string[] Month names (index 1-13) */
    public function ethiopianMonthNames(string $locale = 'am'): array
    {
        return $locale === 'am' ? self::MONTH_NAMES_AM : self::MONTH_NAMES_EN;
    }

    /** @return string[] Weekday names (index 0=Sun … 6=Sat) */
    public function ethiopianWeekdayNames(string $locale = 'am'): array
    {
        return $locale === 'am' ? self::WEEKDAY_NAMES_AM : self::WEEKDAY_NAMES_EN;
    }

    public function formatEthiopianDate(int $year, int $month, int $day, string $locale = 'am'): string
    {
        $months = $this->ethiopianMonthNames($locale);
        $monthName = $months[$month] ?? (string) $month;

        if ($locale === 'am') {
            return "{$monthName} {$day}, {$year}";
        }

        return "{$monthName} {$day}, {$year} E.C.";
    }

    public function formatGregorianDateAsEthiopian(Carbon $date, string $locale = 'am'): string
    {
        $eth = $this->gregorianToEthiopian($date->year, $date->month, $date->day);

        return $this->formatEthiopianDate($eth['year'], $eth['month'], $eth['day'], $locale);
    }

    // ─── Helper ─────────────────────────────────────────────────────────────

    private function assertValidEthiopianDate(int $year, int $month, int $day): void
    {
        if (! $this->isValidEthiopianDate($year, $month, $day)) {
            throw new InvalidArgumentException(
                "Invalid Ethiopian date: {$year}-{$month}-{$day}"
            );
        }
    }
}
