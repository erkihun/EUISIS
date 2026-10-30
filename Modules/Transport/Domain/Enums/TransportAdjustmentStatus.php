<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Enums;

enum TransportAdjustmentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
