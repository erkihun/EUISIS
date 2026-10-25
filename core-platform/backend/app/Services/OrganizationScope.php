<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiClient;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Organization scope = WHERE, always explicit and failing closed (ADR 0008):
 * citywide reaches every organization, subtree an organization and every one
 * under it through the structural edges of the hierarchy in force, self that
 * organization alone. Anything else (no scope type, or a type without its
 * organization) reaches nothing: an empty scope is never the whole city. One
 * rule for every read and write in the Core, for people and API clients.
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
     * A person's scope is their account's; an integration client's is its
     * owner organization's.
     *
     * @return list<string>|null null = the whole city; [] = nothing at all
     */
    public function organizationIds(User|ApiClient $principal): ?array
    {
        [$type, $root] = $principal instanceof User
            ? [$principal->scope_type, $principal->scope_organization_id]
            : [$principal->scope_type, $principal->owner_organization_id];
        if ($type === User::SCOPE_CITYWIDE) {
            return null;
        }
        if ($root === null || ! in_array($type, User::ORGANIZATION_SCOPES, true)) {
            return [];
        }

        return $this->cache[$principal::class.':'.$principal->getKey().':'.$type.':'.$root] ??= $type === User::SCOPE_SELF
            ? [(string) $root]
            : $this->subtree((string) $root);
    }

    /** Whether the principal reaches any organizational data at all. */
    public function hasAny(User|ApiClient $principal): bool
    {
        return $this->organizationIds($principal) !== [];
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
