<?php

declare(strict_types=1);

namespace App\Enums;

enum CafeteriaLedgerEntryType: string
{
    case Allocation            = 'allocation';
    case Usage                 = 'usage';
    case ExtraUsage            = 'extra_usage';
    case CarryForwardDeduction = 'carry_forward_deduction';
    case Adjustment            = 'adjustment';
    case Reversal              = 'reversal';

    public function label(): string
    {
        return match($this) {
            self::Allocation            => 'Allocation',
            self::Usage                 => 'Usage',
            self::ExtraUsage            => 'Extra Usage',
            self::CarryForwardDeduction => 'Carry-forward Deduction',
            self::Adjustment            => 'Adjustment',
            self::Reversal              => 'Reversal',
        };
    }

    public function isDebit(): bool
    {
        return in_array($this, [self::Usage, self::ExtraUsage, self::CarryForwardDeduction], true);
    }

    public function isCredit(): bool
    {
        return in_array($this, [self::Allocation, self::Adjustment, self::Reversal], true);
    }
}
