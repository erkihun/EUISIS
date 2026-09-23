<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\OrganizationUnit;
use App\Models\Position;

/** The master-data entity a request item targets. */
enum OrganizationalChangeEntityType: string
{
    case OrganizationUnit = 'organization_unit';
    case Position = 'position';
    /** A narrative request with no single master-data target. */
    case Narrative = 'narrative';

    /** @return class-string|null */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::OrganizationUnit => OrganizationUnit::class,
            self::Position => Position::class,
            self::Narrative => null,
        };
    }
}
