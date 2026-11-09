<?php

declare(strict_types=1);

namespace App\Enums;

enum AssignmentStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
    case PendingTransfer = 'pending_transfer';
}
