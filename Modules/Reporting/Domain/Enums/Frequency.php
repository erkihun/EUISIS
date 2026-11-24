<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

enum Frequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';
    /** One-off (special) report: the period is typed, nothing recurs. */
    case AdHoc = 'ad_hoc';

    /** Length in months for the month-based frequencies; null otherwise. */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Annual => 12,
            default => null,
        };
    }

    public function recurs(): bool
    {
        return $this !== self::AdHoc;
    }
}
