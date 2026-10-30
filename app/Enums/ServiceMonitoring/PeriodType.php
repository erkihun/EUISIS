<?php

declare(strict_types=1);

namespace App\Enums\ServiceMonitoring;

/** The quarterly cadence the bureau collects on: 3, 6, 9 and 12 months. */
enum PeriodType: string
{
    case FirstQuarter = 'first_quarter';
    case HalfYear = 'half_year';
    case NineMonths = 'nine_months';
    case Annual = 'annual';

    public function defaultNameAm(int $fiscalYear): string
    {
        return match ($this) {
            self::FirstQuarter => "የ{$fiscalYear} በጀት ዓመት የመጀመሪያ ሩብ ዓመት",
            self::HalfYear => "የ{$fiscalYear} በጀት ዓመት የ6 ወር",
            self::NineMonths => "የ{$fiscalYear} በጀት ዓመት የ9 ወር",
            self::Annual => "የ{$fiscalYear} በጀት ዓመት ዓመታዊ",
        };
    }

    public function defaultNameEn(int $fiscalYear): string
    {
        return match ($this) {
            self::FirstQuarter => "FY {$fiscalYear} first quarter",
            self::HalfYear => "FY {$fiscalYear} six months",
            self::NineMonths => "FY {$fiscalYear} nine months",
            self::Annual => "FY {$fiscalYear} annual",
        };
    }
}
