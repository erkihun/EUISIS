<?php

declare(strict_types=1);

namespace App\Backbone\Data;

use App\Models\OrganizationEdge;
use BackedEnum;

/**
 * One relationship between two organizations in a hierarchy version: the
 * structural parent line and the other kinds (functional reporting, oversight,
 * service scope …) alike, told apart by `type`.
 */
final readonly class OrganizationRelationshipData
{
    public function __construct(
        public string $hierarchyVersionUuid,
        public string $parentOrganizationUuid,
        public string $childOrganizationUuid,
        public string $type,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
    ) {}

    public static function fromModel(OrganizationEdge $edge): self
    {
        return new self(
            hierarchyVersionUuid: (string) $edge->hierarchy_version_id,
            parentOrganizationUuid: (string) $edge->parent_organization_id,
            childOrganizationUuid: (string) $edge->child_organization_id,
            type: $edge->relationship_type instanceof BackedEnum ? (string) $edge->relationship_type->value : (string) $edge->relationship_type,
            effectiveFrom: $edge->effective_from?->toDateString(),
            effectiveTo: $edge->effective_to?->toDateString(),
        );
    }
}
