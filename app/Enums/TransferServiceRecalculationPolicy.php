<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferServiceRecalculationPolicy: string
{
    case NoRecalculation = 'no_recalculation';
    case RecalculateFromTransfer = 'recalculate_from_transfer';
    case RecalculateFromEffective = 'recalculate_from_effective_date';

    public function label(): string
    {
        return (string) __('enums.transfer_service_recalculation_policy.'.$this->value);
    }
}
