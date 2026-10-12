<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferOverrideStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return (string) __('enums.transfer_override_status.'.$this->value);
    }
}
