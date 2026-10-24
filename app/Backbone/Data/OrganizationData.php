<?php

declare(strict_types=1);

namespace App\Backbone\Data;

use App\Models\Organization;
use BackedEnum;

/** An organization as the backbone publishes it: identity, names and status. No contact, branding or legal fields. */
final readonly class OrganizationData
{
    public function __construct(
        public string $organizationUuid,
        public string $code,
        public ?string $nameEn,
        public ?string $nameAm,
        public ?string $organizationTypeUuid,
        public string $status,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
    ) {}

    public static function fromModel(Organization $organization): self
    {
        return new self(
            organizationUuid: (string) $organization->getKey(),
            code: (string) $organization->code,
            nameEn: $organization->name_en,
            nameAm: $organization->name_am,
            organizationTypeUuid: $organization->organization_type_id === null ? null : (string) $organization->organization_type_id,
            status: $organization->status instanceof BackedEnum ? (string) $organization->status->value : (string) $organization->status,
            effectiveFrom: $organization->effective_from?->toDateString(),
            effectiveTo: $organization->effective_to?->toDateString(),
        );
    }
}
