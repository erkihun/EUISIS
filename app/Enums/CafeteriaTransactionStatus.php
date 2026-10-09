<?php

declare(strict_types=1);

namespace App\Enums;

enum CafeteriaTransactionStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Reversed = 'reversed';
    case PendingReview = 'pending_review';

    public function label(): string
    {
        return match($this) {
            self::Accepted      => 'Accepted',
            self::Rejected      => 'Rejected',
            self::Reversed      => 'Reversed',
            self::PendingReview => 'Pending Review',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Accepted      => 'green',
            self::Rejected      => 'red',
            self::Reversed      => 'gray',
            self::PendingReview => 'yellow',
        };
    }
}
