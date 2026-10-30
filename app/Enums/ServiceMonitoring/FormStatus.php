<?php

declare(strict_types=1);

namespace App\Enums\ServiceMonitoring;

enum FormStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
    case Archived = 'archived';

    /** Only a draft's content may change; every other version is history. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
