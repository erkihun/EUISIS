<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Domain\Enums;

enum CafeteriaLedgerEntryType: string
{
    case Allocation = 'allocation';
    case Usage = 'usage';
    case ExtraUsage = 'extra_usage';
    case CarryForwardDeduction = 'carry_forward_deduction';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return (string) __('enums.cafeteria_ledger_entry_type.'.$this->value);
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
