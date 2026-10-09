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
        return match ($this) {
            self::Release => 'Release Approval',
            self::Receiving => 'Receiving Approval',
            self::Final => 'Final Approval',
        };
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
