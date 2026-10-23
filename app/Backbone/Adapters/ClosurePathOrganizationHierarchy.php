<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\OrganizationHierarchy;
use App\Models\OrganizationClosurePath;
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
}
