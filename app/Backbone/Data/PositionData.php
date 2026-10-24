<?php

declare(strict_types=1);

namespace App\Backbone\Data;

use App\Models\Position;

/** A position: identity, where it sits and whether it is in use. Occupancy is a separate question (PositionOccupancyData). */
final readonly class PositionData
{
    public function __construct(
        public string $positionUuid,
        public ?string $organizationUuid,
        public ?string $unitUuid,
        public ?string $code,
        public ?string $titleEn,
        public ?string $titleAm,
        public ?string $occupationUuid,
        public bool $isActive,
    ) {}

    public static function fromModel(Position $position): self
    {
        return new self(
            positionUuid: (string) $position->getKey(),
            organizationUuid: $position->organization_id === null ? null : (string) $position->organization_id,
            unitUuid: $position->organization_unit_id === null ? null : (string) $position->organization_unit_id,
            code: $position->job_position_code,
            titleEn: $position->title_en,
            titleAm: $position->title_am,
            occupationUuid: $position->occupation_id === null ? null : (string) $position->occupation_id,
            isActive: (bool) $position->is_active,
        );
    }
}
