<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Calendar;

use InvalidArgumentException;

/**
 * A civil date in the Ethiopian calendar (Amete Mihret) and its conversion to
 * and from the Gregorian calendar through the Julian Day Number. Pure
 * arithmetic: no timezone, no framework.
 *
 * Thirteen months: twelve of 30 days, then Pagume with 5 days, or 6 in a leap
 * year (year mod 4 = 3).
 */
final readonly class EthiopianDate
{
    private const EPOCH = 1723856;

    public const MONTHS_EN = [1 => 'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazya', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];

    public const MONTHS_AM = [1 => 'መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];

    public function __construct(public int $year, public int $month, public int $day)
    {
        if ($month < 1 || $month > 13 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            throw new InvalidArgumentException("Not an Ethiopian date: {$year}-{$month}-{$day}");
        }
    }

    public static function isLeapYear(int $year): bool
    {
        return $year % 4 === 3;
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return $month === 13 ? (self::isLeapYear($year) ? 6 : 5) : 30;
    }

    /** @param string $iso a Gregorian date, Y-m-d */
    public static function fromIso(string $iso): self
    {
        return self::fromJdn(self::isoToJdn($iso));
    }

    public static function fromJdn(int $jdn): self
    {
        $days = $jdn - self::EPOCH;
        $r = (($days % 1461) + 1461) % 1461;
        $n = ($r % 365) + 365 * intdiv($r, 1460);
        $year = 4 * (int) floor($days / 1461) + intdiv($r, 365) - intdiv($r, 1460);

        return new self($year, intdiv($n, 30) + 1, ($n % 30) + 1);
    }

    public function toJdn(): int
    {
        return self::EPOCH + 365 + 365 * ($this->year - 1) + intdiv($this->year, 4) + 30 * $this->month + $this->day - 31;
    }

    /** The same day in the Gregorian calendar, Y-m-d. */
    public function toIso(): string
    {
        return self::jdnToIso($this->toJdn());
    }

    public static function isoToJdn(string $iso): int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Not a Gregorian date: {$iso}");
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $a = intdiv($mo - 14, 12);

        return intdiv(1461 * ($y + 4800 + $a), 4) + intdiv(367 * ($mo - 2 - 12 * $a), 12) - intdiv(3 * intdiv($y + 4900 + $a, 100), 4) + $d - 32075;
    }

    public static function jdnToIso(int $jdn): string
    {
        $l = $jdn + 68569;
        $n = intdiv(4 * $l, 146097);
        $l -= intdiv(146097 * $n + 3, 4);
        $i = intdiv(4000 * ($l + 1), 1461001);
        $l = $l - intdiv(1461 * $i, 4) + 31;
        $j = intdiv(80 * $l, 2447);
        $d = $l - intdiv(2447 * $j, 80);
        $l = intdiv($j, 11);
        $m = $j + 2 - 12 * $l;
        $y = 100 * ($n - 49) + $i + $l;

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** "11 Hidar 2019" or "ኅዳር 11 ቀን 2019 ዓ.ም". */
    public function format(string $language = 'en'): string
    {
        return $language === 'am'
            ? sprintf('%s %d ቀን %d ዓ.ም', self::MONTHS_AM[$this->month], $this->day, $this->year)
            : sprintf('%d %s %d EC', $this->day, self::MONTHS_EN[$this->month], $this->year);
    }
}
