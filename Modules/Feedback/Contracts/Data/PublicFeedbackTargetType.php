<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\Data;

/**
 * Who a public submission is about: the employee whose feedback QR was
 * scanned, or the organization whose feedback QR was scanned. There is no
 * third kind and no free text; an organization unit is not a target.
 */
enum PublicFeedbackTargetType: string
{
    case Employee = 'employee';
    case Organization = 'organization';

    public function label(string $locale = 'en'): string
    {
        return match ($this) {
            self::Employee => $locale === 'am' ? 'ሠራተኛ' : 'Employee',
            self::Organization => $locale === 'am' ? 'ተቋም' : 'Organization',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
