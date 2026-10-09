<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * Lifecycle of an assessment form version. Only a DRAFT is edited; a
 * PUBLISHED version is immutable and is what assessments use. Publishing a
 * newer version SUPERSEDES it; ARCHIVED versions are no longer offered.
 */
enum FormVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
    case Archived = 'archived';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
