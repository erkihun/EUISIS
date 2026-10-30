<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Domain\Enums;

enum CafeteriaSettlementStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';
}
