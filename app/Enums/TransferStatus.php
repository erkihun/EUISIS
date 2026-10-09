<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case CurrentOrganizationConfirmed = 'current_organization_confirmed';
    case ReceivingOrganizationConfirmed = 'receiving_organization_confirmed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::Completed], true);
    }
}
