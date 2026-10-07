<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * Whether a criterion takes a comment or evidence: not at all, optionally, or required.
 */
enum InputMode: string
{
    case Disabled = 'disabled';
    case Optional = 'optional';
    case Required = 'required';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
