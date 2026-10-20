<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** Why content was reported. */
enum ReportCategory: string
{
    case Spam = 'SPAM';
    case Harassment = 'HARASSMENT';
    case InappropriateContent = 'INAPPROPRIATE_CONTENT';
    case SecurityConcern = 'SECURITY_CONCERN';
    case Other = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
