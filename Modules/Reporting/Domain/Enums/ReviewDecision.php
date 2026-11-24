<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

enum ReviewDecision: string
{
    case Approve = 'approve';
    case Return = 'return';
    case Reject = 'reject';

    public function requiresReason(): bool
    {
        return $this !== self::Approve;
    }
}
