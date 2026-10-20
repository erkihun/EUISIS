<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Enums\HierarchyVersionStatus;
use App\Models\Employee;
use App\Models\HierarchyVersion;
use App\Models\OrganizationClosurePath;
use App\Models\TransportRoute;

/**
 * Who may ride a route, by the route's assigned organization and scope:
 * citywide (or no organization) serves everyone, `self` serves that office
 * only, and `subtree` serves the office and everything beneath it in the
 * published hierarchy.
 */
class TransportRouteAccessService
{
    /** @var array<string, list<string>> per-request cache of ancestor ids */
    private array $ancestors = [];

    public function employeeCanUseRoute(Employee $employee, TransportRoute $route): bool
    {
        if ($route->assigned_organization_id === null || $route->assigned_scope_type === 'citywide') {
            return true;
        }

        $organizationId = $employee->currentAssignment?->organization_id;

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
     * The organization's proper ancestors on the published hierarchy
     * (nearest first). Used for subtree route scopes and subtree grants.
     *
     * @return list<string>
     */
    public function ancestorIds(string $organizationId): array
    {
        if (isset($this->ancestors[$organizationId])) {
            return $this->ancestors[$organizationId];
        }

        $publishedVersionId = HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published)
            ->orderByDesc('effective_from')
            ->value('id');

        return $this->ancestors[$organizationId] = OrganizationClosurePath::query()
            ->where('descendant_organization_id', $organizationId)
            ->where('depth', '>', 0)
            ->when($publishedVersionId !== null, fn ($query) => $query->where('hierarchy_version_id', $publishedVersionId))
            ->orderBy('depth')
            ->pluck('ancestor_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }
}
