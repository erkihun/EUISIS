<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferApprovalType: string
{
    case Release = 'release';
    case Receiving = 'receiving';
    case Final = 'final';

    public function label(): string
    {
        return (string) __('enums.transfer_approval_type.'.$this->value);
    }

    /** Status that the application must be in before this approval type is triggered. */
    public function requiredApplicationStatus(): TransferApplicationStatus
    {
        return match ($this) {
            self::Release => TransferApplicationStatus::ReleasePending,
            self::Receiving => TransferApplicationStatus::ReceivingPending,
            self::Final => TransferApplicationStatus::FinalApprovalPending,
        };
    }
}
