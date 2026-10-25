<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Backbone\Contracts\OrganizationHierarchy;
use App\Models\TransportRoute;

/**
 * Who may ride a route, by the route's assigned organization and scope:
 * citywide (or no organization) serves everyone, `self` serves that office
 * only, and `subtree` serves the office and everything beneath it in the
 * hierarchy in force.
 *
 * The hierarchy comes from the Core OrganizationHierarchy contract (Stage 3),
 * so transport no longer keeps its own copy of the subtree rule.
 */
class TransportRouteAccessService
{
    /** @var array<string, list<string>> per-request cache of ancestor ids */
    private array $ancestors = [];

    public function __construct(private readonly OrganizationHierarchy $hierarchy) {}

    /** Whether a rider placed in this organization (their current assignment's) may use the route. */
    public function organizationCanUseRoute(?string $organizationId, TransportRoute $route): bool
    {
        if ($route->assigned_organization_id === null || $route->assigned_scope_type === 'citywide') {
            return true;
        }

        if ($organizationId === null) {
            return false;
        }

        if ($organizationId === $route->assigned_organization_id) {
            return true;
        }

        return $route->assigned_scope_type === 'subtree'
            && in_array($route->assigned_organization_id, $this->ancestorIds($organizationId), true);
    }

    /**
     * The organization's proper ancestors in the hierarchy in force (nearest
     * first). Used for subtree route scopes and subtree grants.
     *
     * @return list<string>
     */
    public function ancestorIds(string $organizationId): array
    {
        return $this->ancestors[$organizationId] ??= $this->hierarchy->ancestorIds($organizationId);
    }
}
