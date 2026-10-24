<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\OrganizationHierarchy;
use App\Backbone\Data\OrganizationRelationshipData;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationEdge;
use App\Services\Hierarchy\HierarchyVersionResolver;
use Illuminate\Support\Str;

final class ClosurePathOrganizationHierarchy implements OrganizationHierarchy
{
    public function __construct(private readonly HierarchyVersionResolver $versions) {}

    public function isSameOrDescendant(string $organizationUuid, string $ancestorUuid, ?string $hierarchyVersionUuid = null): bool
    {
        if (! Str::isUuid($organizationUuid) || ! Str::isUuid($ancestorUuid)) {
            return false;
        }
        if ($organizationUuid === $ancestorUuid) {
            return true;
        }

        $version = $hierarchyVersionUuid ?? $this->versions->resolveDefault()?->getKey();

        return $version !== null && OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $version)
            ->where('ancestor_organization_id', $ancestorUuid)
            ->where('descendant_organization_id', $organizationUuid)
            ->exists();
    }

    public function subtreeIds(string $organizationUuid, ?string $hierarchyVersionUuid = null): array
    {
        if (! Str::isUuid($organizationUuid)) {
            return [];
        }

        $version = $hierarchyVersionUuid ?? $this->versions->resolveDefault()?->getKey();
        $descendants = $version === null ? [] : OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $version)
            ->where('ancestor_organization_id', $organizationUuid)
            ->where('descendant_organization_id', '!=', $organizationUuid)
            ->orderBy('depth')
            ->pluck('descendant_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return array_values(array_unique([$organizationUuid, ...$descendants]));
    }

    public function childIds(string $organizationUuid, ?string $hierarchyVersionUuid = null): array
    {
        $version = $this->version($organizationUuid, $hierarchyVersionUuid);

        return $version === null ? [] : OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $version)
            ->where('ancestor_organization_id', $organizationUuid)
            ->where('depth', 1)
            ->pluck('descendant_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function ancestorIds(string $organizationUuid, ?string $hierarchyVersionUuid = null): array
    {
        $version = $this->version($organizationUuid, $hierarchyVersionUuid);

        return $version === null ? [] : OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $version)
            ->where('descendant_organization_id', $organizationUuid)
            ->where('depth', '>', 0)
            ->orderBy('depth')
            ->pluck('ancestor_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function relationships(string $organizationUuid, ?string $hierarchyVersionUuid = null): array
    {
        $version = $this->version($organizationUuid, $hierarchyVersionUuid);

        return $version === null ? [] : OrganizationEdge::query()
            ->where('hierarchy_version_id', $version)
            ->where(fn ($query) => $query->where('parent_organization_id', $organizationUuid)->orWhere('child_organization_id', $organizationUuid))
            ->orderBy('relationship_type')
            ->get()
            ->map(fn (OrganizationEdge $edge): OrganizationRelationshipData => OrganizationRelationshipData::fromModel($edge))
            ->values()
            ->all();
    }

    /** The version asked for, or the one in force; null for a malformed id or no structure at all. */
    private function version(string $organizationUuid, ?string $hierarchyVersionUuid): ?string
    {
        if (! Str::isUuid($organizationUuid)) {
            return null;
        }
        $version = $hierarchyVersionUuid ?? $this->versions->resolveDefault()?->getKey();

        return $version === null ? null : (string) $version;
    }
}
