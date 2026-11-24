<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Calendar;

use InvalidArgumentException;
use Modules\Reporting\Domain\Enums\CalendarBasis;
use Modules\Reporting\Domain\Enums\Frequency;

/**
 * Recurring reporting periods on the Ethiopian or the Gregorian calendar.
 *
 * Month-based periods are blocks of 1, 3, 6 or 12 months counted from the
 * fiscal year's first month. On the Ethiopian calendar Pagume (5 or 6 days)
 * has no period of its own: it closes the Nehase period, so every day of the
 * year falls in exactly one period and a leap year only lengthens that one.
 * A fiscal year that does not start in the first month is named after the
 * calendar year it ends in (the Ethiopian budget year 2019 starts on
 * Hamle 1, 2018).
 */
final class PeriodCalculator
{
    private const GREGORIAN_EN = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const GREGORIAN_AM = [1 => 'ጃንዋሪ', 'ፌብሯሪ', 'ማርች', 'ኤፕሪል', 'ሜይ', 'ጁን', 'ጁላይ', 'ኦገስት', 'ሴፕቴምበር', 'ኦክቶበር', 'ኖቬምበር', 'ዲሴምበር'];

    /** The period that contains the Gregorian date $iso. */
    public function containing(Frequency $frequency, CalendarBasis $basis, string $iso, int $fiscalStartMonth = 1): ReportingPeriod
    {
        if ($frequency === Frequency::AdHoc) {
            throw new InvalidArgumentException('An ad hoc report has no recurring period.');
        }
        if ($fiscalStartMonth < 1 || $fiscalStartMonth > 12) {
            throw new InvalidArgumentException('The fiscal year starts in month 1 to 12.');
        }
        if ($frequency === Frequency::Weekly) {
            return $this->week($iso);
        }

        return $basis === CalendarBasis::Ethiopian
            ? $this->ethiopianBlock($frequency, $iso, $fiscalStartMonth)
            : $this->gregorianBlock($frequency, $iso, $fiscalStartMonth);
    }

    public function following(ReportingPeriod $period, Frequency $frequency, CalendarBasis $basis, int $fiscalStartMonth = 1): ReportingPeriod
    {
        return $this->containing($frequency, $basis, EthiopianDate::jdnToIso(EthiopianDate::isoToJdn($period->endDate) + 1), $fiscalStartMonth);
    }

    /**
     * Periods that END between the two dates (inclusive), oldest first.
     *
     * @return list<ReportingPeriod>
     */
    public function endingBetween(Frequency $frequency, CalendarBasis $basis, string $fromIso, string $toIso, int $fiscalStartMonth = 1, int $limit = 60): array
    {
        $periods = [];
        $period = $this->containing($frequency, $basis, $fromIso, $fiscalStartMonth);
        while (count($periods) < $limit && $period->endDate <= $toIso) {
            if ($period->endDate >= $fromIso) {
                $periods[] = $period;
            }
            $period = $this->following($period, $frequency, $basis, $fiscalStartMonth);
        }

        return $periods;
    }

    private function week(string $iso): ReportingPeriod
    {
        $jdn = EthiopianDate::isoToJdn($iso);
        $monday = $jdn - ($jdn % 7);
        $start = EthiopianDate::jdnToIso($monday);

        return new ReportingPeriod("W-{$start}", $start, EthiopianDate::jdnToIso($monday + 6), "Week of {$start}", "የ{$start} ሳምንት");
    }

    private function ethiopianBlock(Frequency $frequency, string $iso, int $fiscalStart): ReportingPeriod
    {
        $date = EthiopianDate::fromIso($iso);
        // Pagume belongs to the Nehase period.
        $ordinal = $date->year * 12 + min($date->month, 12) - 1;
        [$start, $end, $fiscalYear, $index] = $this->block($ordinal, (int) $frequency->months(), $fiscalStart);

        [$startYear, $startMonth] = [intdiv($start, 12), $start % 12 + 1];
        [$endYear, $endMonth] = [intdiv($end, 12), $end % 12 + 1];
        $first = new EthiopianDate($startYear, $startMonth, 1);
        $last = $endMonth === 12
            ? new EthiopianDate($endYear, 13, EthiopianDate::daysInMonth($endYear, 13))
            : new EthiopianDate($endYear, $endMonth, 30);

        [$key, $en, $am] = match ($frequency) {
            Frequency::Monthly => [
                sprintf('EC-%04d-M%02d', $startYear, $startMonth),
                ($startMonth === 12 ? 'Nehase–Pagume' : EthiopianDate::MONTHS_EN[$startMonth])." {$startYear} EC",
                ($startMonth === 12 ? 'ነሐሴ–ጳጉሜ' : EthiopianDate::MONTHS_AM[$startMonth])." {$startYear} ዓ.ም",
            ],
            Frequency::Quarterly => ["EC-FY{$fiscalYear}-Q{$index}", "Quarter {$index}, EFY {$fiscalYear}", "{$index}ኛ ሩብ ዓመት፣ {$fiscalYear} በጀት ዓመት"],
            Frequency::Semiannual => ["EC-FY{$fiscalYear}-H{$index}", "Half {$index}, EFY {$fiscalYear}", "{$index}ኛ መንፈቅ ዓመት፣ {$fiscalYear} በጀት ዓመት"],
            default => ["EC-FY{$fiscalYear}", "EFY {$fiscalYear}", "{$fiscalYear} በጀት ዓመት"],
        };

        return new ReportingPeriod($key, $first->toIso(), $last->toIso(), $en, $am);
    }

    private function gregorianBlock(Frequency $frequency, string $iso, int $fiscalStart): ReportingPeriod
    {
        [$year, $month] = [(int) substr($iso, 0, 4), (int) substr($iso, 5, 2)];
        [$start, $end, $fiscalYear, $index] = $this->block($year * 12 + $month - 1, (int) $frequency->months(), $fiscalStart);

        [$startYear, $startMonth] = [intdiv($start, 12), $start % 12 + 1];
        [$endYear, $endMonth] = [intdiv($end, 12), $end % 12 + 1];
        $lastDay = (int) date('t', (int) gmmktime(0, 0, 0, $endMonth, 1, $endYear));

        [$key, $en, $am] = match ($frequency) {
            Frequency::Monthly => [
                sprintf('GC-%04d-M%02d', $startYear, $startMonth),
                self::GREGORIAN_EN[$startMonth]." {$startYear}",
                self::GREGORIAN_AM[$startMonth]." {$startYear} (እ.ኤ.አ.)",
            ],
            Frequency::Quarterly => ["GC-FY{$fiscalYear}-Q{$index}", "Quarter {$index}, FY {$fiscalYear}", "{$index}ኛ ሩብ ዓመት፣ {$fiscalYear} (እ.ኤ.አ.)"],
            Frequency::Semiannual => ["GC-FY{$fiscalYear}-H{$index}", "Half {$index}, FY {$fiscalYear}", "{$index}ኛ መንፈቅ ዓመት፣ {$fiscalYear} (እ.ኤ.አ.)"],
            default => ["GC-FY{$fiscalYear}", "FY {$fiscalYear}", "{$fiscalYear} ዓመት (እ.ኤ.አ.)"],
        };

        return new ReportingPeriod($key, sprintf('%04d-%02d-01', $startYear, $startMonth), sprintf('%04d-%02d-%02d', $endYear, $endMonth, $lastDay), $en, $am);
    }

    /**
     * The block of $span months that holds month-ordinal $ordinal, counted
     * from the fiscal year's first month.
     *
     * @return array{int, int, int, int} first ordinal, last ordinal, fiscal year, index within the year (1-based)
     */
    private function block(int $ordinal, int $span, int $fiscalStart): array
    {
        $offset = $fiscalStart - 1;
        $start = (int) floor(($ordinal - $offset) / $span) * $span + $offset;
        $yearStart = (int) floor(($ordinal - $offset) / 12) * 12 + $offset;

        return [$start, $start + $span - 1, intdiv($yearStart, 12) + ($fiscalStart > 1 ? 1 : 0), intdiv($start - $yearStart, $span) + 1];
    }
}
