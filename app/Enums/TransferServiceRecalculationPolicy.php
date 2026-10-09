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
        return match ($this) {
            self::NoRecalculation => 'No Recalculation',
            self::RecalculateFromTransfer => 'Recalculate from Transfer Date',
            self::RecalculateFromEffective => 'Recalculate from Effective Date',
        };
    }
}
