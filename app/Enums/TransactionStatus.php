<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionStatus: string
{
    case Authorized = 'authorized';
    case Denied = 'denied';
    case PendingSync = 'pending_sync';
    case Reversed = 'reversed';
    case Settled = 'settled';
}
