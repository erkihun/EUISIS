<?php

declare(strict_types=1);

namespace Modules\Performance\Contracts\Data;

enum AgreementStatus: string
{
    case Draft = 'DRAFT';
    case PendingEmployeeReview = 'PENDING_EMPLOYEE_REVIEW';
    case PendingManagerApproval = 'PENDING_MANAGER_APPROVAL';
    case Returned = 'RETURNED';
    case Agreed = 'AGREED';
    case Active = 'ACTIVE';
    case UnderReview = 'UNDER_REVIEW';
    case Finalized = 'FINALIZED';
    case Closed = 'CLOSED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
