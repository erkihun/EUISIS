<?php

declare(strict_types=1);

namespace App\Enums;

enum EstablishmentStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Archived = 'archived';

    public function isFinal(): bool
    {
        return $this === self::Archived;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Archived => 'Archived',
        };
    }
}
