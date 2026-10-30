<?php

declare(strict_types=1);

namespace App\Enums;

enum GrievanceResponseStatus: string
{
    case Draft = 'draft';
    case Compiled = 'compiled';
    case AwaitingManagerApproval = 'awaiting_manager_approval';
    case ApprovedByManager = 'approved_by_manager';
    case RejectedByManager = 'rejected_by_manager';
    case Issued = 'issued';
}
