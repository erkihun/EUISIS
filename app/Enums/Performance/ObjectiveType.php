<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum ObjectiveType: string
{
    case Strategic = 'STRATEGIC';
    case Annual = 'ANNUAL';
    case Operational = 'OPERATIONAL';
    case Support = 'SUPPORT';
    case Local = 'LOCAL';
    case Inherited = 'INHERITED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
