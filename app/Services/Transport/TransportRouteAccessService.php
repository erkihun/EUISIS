<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\Employee;
use App\Models\TransportRoute;

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

        if ($route->assigned_scope_type === 'self') {
            return $organizationId === $route->assigned_organization_id;
        }

        // Conservative fallback until a transport-specific subtree query is needed.
        return $organizationId === $route->assigned_organization_id;
    }
}
