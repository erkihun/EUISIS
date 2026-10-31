<?php

declare(strict_types=1);

namespace Modules\Performance\Contracts\Data;

enum AppealStatus: string
{
    case Submitted = 'SUBMITTED';
    case UnderReview = 'UNDER_REVIEW';
    case Decided = 'DECIDED';
    case Withdrawn = 'WITHDRAWN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
