<?php

declare(strict_types=1);

namespace App\Enums;

enum EntitlementStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
