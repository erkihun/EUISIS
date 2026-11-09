<?php

declare(strict_types=1);

namespace App\Backbone\Data;

use App\Models\OrganizationUnit;
use BackedEnum;

/** An organizational unit: identity, place in its organization, names and status. */
final readonly class OrganizationUnitData
{
    public function __construct(
        public string $unitUuid,
        public string $organizationUuid,
        public ?string $parentUnitUuid,
        public ?string $unitTypeUuid,
        public ?string $code,
        public ?string $nameEn,
        public ?string $nameAm,
        public string $status,
    ) {}

    public static function fromModel(OrganizationUnit $unit): self
    {
        return new self(
            unitUuid: (string) $unit->getKey(),
            organizationUuid: (string) $unit->organization_id,
            parentUnitUuid: $unit->parent_unit_id === null ? null : (string) $unit->parent_unit_id,
            unitTypeUuid: $unit->organization_unit_type_id === null ? null : (string) $unit->organization_unit_type_id,
            code: $unit->code,
            nameEn: $unit->name_en,
            nameAm: $unit->name_am,
            status: $unit->status instanceof BackedEnum ? (string) $unit->status->value : (string) $unit->status,
        );
    }
}
