<?php

declare(strict_types=1);

namespace App\Enums\Performance;

/**
 * How a child plan takes up a parent objective.
 */
enum CascadeMode: string
{
    case Accept = 'ACCEPT';
    case Customize = 'CUSTOMIZE';
    case Split = 'SPLIT';
    case Contribute = 'CONTRIBUTE';
    case LocalOnly = 'LOCAL_ONLY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
