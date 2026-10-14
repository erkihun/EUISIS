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
            && $this->isDescendant($organizationId, $route->assigned_organization_id);
    }

    private function isDescendant(string $organizationId, string $ancestorId): bool
    {
        $publishedVersionId = HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published)
            ->orderByDesc('effective_from')
            ->value('id');

        return OrganizationClosurePath::query()
            ->where('ancestor_organization_id', $ancestorId)
            ->where('descendant_organization_id', $organizationId)
            ->where('depth', '>', 0)
            ->when($publishedVersionId !== null, fn ($query) => $query->where('hierarchy_version_id', $publishedVersionId))
            ->exists();
    }
}
