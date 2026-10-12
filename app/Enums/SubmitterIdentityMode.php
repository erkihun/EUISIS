<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The identity policy a submission was taken under (brief §34). Recorded on
 * the row so a later policy change cannot reinterpret what a visitor agreed
 * to when they submitted.
 */
enum SubmitterIdentityMode: string
{
    /** No name or contact is collected. */
    case Anonymous = 'anonymous';

    /** Name and contact are required. */
    case Identified = 'identified';

    /** Name and contact are offered but not required. */
    case Optional = 'optional';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
