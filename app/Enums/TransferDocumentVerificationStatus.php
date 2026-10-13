<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferDocumentVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return (string) __('enums.transfer_document_verification_status.'.$this->value);
    }
}
