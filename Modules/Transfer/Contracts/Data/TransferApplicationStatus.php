<?php

declare(strict_types=1);

namespace Modules\Transfer\Contracts\Data;

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
        return (string) __('enums.transfer_application_status.'.$this->value);
    }
}
