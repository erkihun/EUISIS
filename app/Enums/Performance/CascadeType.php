<?php

declare(strict_types=1);

namespace App\Enums\Performance;

/**
 * Recorded lineage type of a parent -> child objective link.
 */
enum CascadeType: string
{
    case Inherited = 'INHERITED';
    case Customized = 'CUSTOMIZED';
    case Split = 'SPLIT';
    case Contribution = 'CONTRIBUTION';
    case Supporting = 'SUPPORTING';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
