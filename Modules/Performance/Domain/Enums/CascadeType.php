<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Enums;

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
