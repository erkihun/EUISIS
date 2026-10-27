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
        return (string) __('enums.cafeteria_transaction_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Accepted => 'green',
            self::Rejected => 'red',
            self::Reversed => 'gray',
            self::PendingReview => 'yellow',
        };
    }
}
