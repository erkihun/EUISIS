<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\OrganizationRelationshipData;

/**
 * Questions about the versioned organization structure. Without a version id
 * the answer uses the structure version in force (published, else latest draft).
 */
interface OrganizationHierarchy
{
    public function isSameOrDescendant(string $organizationUuid, string $ancestorUuid, ?string $hierarchyVersionUuid = null): bool;

    /** @return list<string> the organization itself first, then its descendants by depth */
    public function subtreeIds(string $organizationUuid, ?string $hierarchyVersionUuid = null): array;

    /** @return list<string> the organizations directly under this one in the structure */
    public function childIds(string $organizationUuid, ?string $hierarchyVersionUuid = null): array;

    /** @return list<string> the organizations above this one, nearest first */
    public function ancestorIds(string $organizationUuid, ?string $hierarchyVersionUuid = null): array;

    /**
     * Every relationship the organization takes part in, as parent or child:
     * structural and the other kinds (functional, oversight, …), told apart by type.
     *
     * @return list<OrganizationRelationshipData>
     */
    public function relationships(string $organizationUuid, ?string $hierarchyVersionUuid = null): array;
}
