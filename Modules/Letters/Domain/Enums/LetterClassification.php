<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/**
 * Security classification (ISO 15489 "access and security classification").
 * Public and internal letters are readable by everyone who may see the
 * registry; confidential and secret letters show their contents only to the
 * people handling them and to holders of letters.view_confidential.
 */
enum LetterClassification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Secret = 'secret';

    public function isRestricted(): bool
    {
        return $this === self::Confidential || $this === self::Secret;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
