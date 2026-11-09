<?php

declare(strict_types=1);

namespace App\Enums;

enum CardStatus: string
{
    case PendingPrint = 'pending_print';
    case Printed = 'printed';
    case Issued = 'issued';
    case Active = 'active';
    case Lost = 'lost';
    case Damaged = 'damaged';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Replaced = 'replaced';
}
