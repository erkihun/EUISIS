<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferApplicationStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Selected = 'selected';
    case ReleasePending = 'release_pending';
    case ReceivingPending = 'receiving_pending';
    case FinalApprovalPending = 'final_approval_pending';
    case Approved = 'approved';
    case ImplementationFailed = 'implementation_failed';
    case Transferred = 'transferred';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Transferred,
            self::Rejected,
            self::Withdrawn,
            self::Cancelled,
        ], true);
    }

    public function isPending(): bool
    {
        return in_array($this, [
            self::Submitted,
            self::UnderReview,
            self::Verified,
            self::Selected,
            self::ReleasePending,
            self::ReceivingPending,
            self::FinalApprovalPending,
            self::Approved,
        ], true);
    }

    public function isInApprovalChain(): bool
    {
        return in_array($this, [
            self::ReleasePending,
            self::ReceivingPending,
            self::FinalApprovalPending,
            self::Approved,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::Verified => 'Verified',
            self::Selected => 'Selected',
            self::ReleasePending => 'Release Pending',
            self::ReceivingPending => 'Receiving Pending',
            self::FinalApprovalPending => 'Final Approval Pending',
            self::Approved => 'Approved',
            self::ImplementationFailed => 'Implementation Failed',
            self::Transferred => 'Transferred',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::Cancelled => 'Cancelled',
        };
    }
}
