<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** A template is edited as a draft, published as a numbered version, and archived when no longer offered. */
enum TemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
