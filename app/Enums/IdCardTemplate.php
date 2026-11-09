<?php

declare(strict_types=1);

namespace App\Enums;

enum IdCardTemplate: string
{
    case Classic = 'classic';
    case Modern = 'modern';
    case Minimal = 'minimal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $template): string => $template->value,
            self::cases(),
        );
    }
}
