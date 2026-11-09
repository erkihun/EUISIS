<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** The official report ranges (D3): one quarter, or the cumulative 6-month, 9-month and annual reports. */
enum ReportRange: string
{
    case Quarter = 'quarter';
    case SixMonths = 'six_months';
    case NineMonths = 'nine_months';
    case Annual = 'annual';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.report_range.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The quarters a range covers. A single-quarter range covers $quarter; the
     * cumulative ranges always start at Q1 of the fiscal year.
     *
     * @return list<int>
     */
    public function quarters(int $quarter): array
    {
        return match ($this) {
            self::Quarter => [$quarter],
            self::SixMonths => [1, 2],
            self::NineMonths => [1, 2, 3],
            self::Annual => [1, 2, 3, 4],
        };
    }
}
