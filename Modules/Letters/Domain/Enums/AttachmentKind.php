<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** What a stored file is: the scanned original, an annex, or the PDF rendered at dispatch. */
enum AttachmentKind: string
{
    case Scan = 'scan';
    case Annex = 'annex';
    case Rendered = 'rendered';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
