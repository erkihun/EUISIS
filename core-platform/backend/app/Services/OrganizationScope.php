<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiClient;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Organization scope = WHERE: a scoped user reaches their organization and every
 * organization under it through structural edges of the published hierarchy;
 * a user with no scope organization reaches the whole city. One rule for every
 * read and write in the Core.
 */
final class OrganizationScope
{
    /** @var array<string, list<string>> per-request cache */
    private array $cache = [];

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * A person's scope is their account's organization; an integration
     * client's is its owner organization. None means the whole city.
     *
     * @return list<string>|null null = the whole city
     */
    public function organizationIds(User|ApiClient $principal): ?array
    {
        $root = $principal instanceof User ? $principal->scope_organization_id : $principal->owner_organization_id;
        if ($root === null) {
            return null;
        }

        return $this->cache[$principal::class.':'.$principal->getKey()] ??= $this->subtree((string) $root);
    }

    public function allows(User|ApiClient $principal, ?string $organizationId): bool
    {
        $ids = $this->organizationIds($principal);

        return $ids === null || ($organizationId !== null && in_array($organizationId, $ids, true));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function apply(Builder $query, User|ApiClient $user, string $column = 'organization_id'): Builder
    {
        $ids = $this->organizationIds($user);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /** @return list<string> the organization first, then everything under it */
    public function subtree(string $organizationId): array
    {
        $version = HierarchyVersion::current();
        $ids = [$organizationId];
        if ($version === null) {
            return $ids;
        }

        $children = OrganizationEdge::query()
            ->where('hierarchy_version_id', $version->id)
            ->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)
            ->get(['parent_organization_id', 'child_organization_id'])
            ->groupBy('parent_organization_id');

        // Breadth-first, each organization once: a cycle in bad data cannot loop.
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($children->get($ids[$i], collect()) as $edge) {
                if (! in_array($edge->child_organization_id, $ids, true)) {
                    $ids[] = (string) $edge->child_organization_id;
                }
            }
        }

        return $ids;
    }
}
