<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

/**
 * Questions about the versioned organization structure. Without a version id
 * the answer uses the structure version in force (published, else latest draft).
 */
interface OrganizationHierarchy
{
    public function isSameOrDescendant(string $organizationUuid, string $ancestorUuid, ?string $hierarchyVersionUuid = null): bool;

    /** @return list<string> the organization itself first, then its descendants by depth */
    public function subtreeIds(string $organizationUuid, ?string $hierarchyVersionUuid = null): array;
}
