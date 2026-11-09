<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

/**
 * The organization above a given one in the published hierarchy on the
 * graph's date, with that organization's head position when it has one.
 */
final readonly class ParentOrganizationLink
{
    public function __construct(
        public bool $exists,
        public bool $ambiguous,
        public ?string $organizationId,
        public ?string $organizationNameEn,
        public ?string $organizationNameAm,
        public ?PositionNode $headPosition,
        /** More than one position is marked as that organization's head. */
        public bool $headAmbiguous = false,
    ) {}

    public static function none(): self
    {
        return new self(false, false, null, null, null, null);
    }
}
