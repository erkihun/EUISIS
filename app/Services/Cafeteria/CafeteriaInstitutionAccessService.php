<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\HierarchyVersionStatus;
use App\Models\CafeteriaProvider;
use App\Models\Employee;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use Illuminate\Database\Eloquent\Builder;

class CafeteriaInstitutionAccessService
{
    public function employeeOrganizationId(Employee $employee): ?string
    {
        return $employee->currentAssignment?->organization_id;
    }

    public function employeeOrganization(Employee $employee): ?Organization
    {
        return $employee->currentAssignment?->organization;
    }

    /**
     * Returns true if the employee is allowed to use the given cafeteria provider
     * based on the provider's institution assignment.
     *
     * Rules:
     *  - If the provider has no organization assigned, access is unrestricted.
     *  - scope_type = "self"    → employee's org must exactly match provider's org.
     *  - scope_type = "subtree" → employee's org must be provider's org or any descendant.
     */
    public function canEmployeeUseProvider(Employee $employee, CafeteriaProvider $provider): bool
    {
        if ($provider->organization_id === null) {
            return true;
        }

        $employeeOrgId = $this->employeeOrganizationId($employee);

        if ($employeeOrgId === null) {
            return false;
        }

        if ($employeeOrgId === $provider->organization_id) {
            return true;
        }

        if (($provider->assigned_scope_type ?? 'self') === 'subtree') {
            return $this->isDescendant($employeeOrgId, $provider->organization_id);
        }

        return false;
    }

    public function resolveDenialReason(Employee $employee, CafeteriaProvider $provider): string
    {
        return 'wrong_institution';
    }

    /**
     * Scope a query to only return providers accessible to the given employee's organization.
     *
     * @param  Builder<CafeteriaProvider>  $query
     * @return Builder<CafeteriaProvider>
     */
    public function filterProvidersForEmployee(Employee $employee, Builder $query): Builder
    {
        $employeeOrgId = $this->employeeOrganizationId($employee);

        if ($employeeOrgId === null) {
            return $query->whereRaw('1 = 0');
        }

        $publishedVersionId = $this->publishedVersionId();

        return $query->where(function (Builder $q) use ($employeeOrgId, $publishedVersionId): void {
            // provider has no restriction
            $q->whereNull('organization_id')
              // or employee is in exact org
              ->orWhere('organization_id', $employeeOrgId)
              // or subtree scope and employee org is a descendant
              ->orWhere(function (Builder $inner) use ($employeeOrgId, $publishedVersionId): void {
                  $inner->where('assigned_scope_type', 'subtree')
                        ->whereExists(function (\Illuminate\Database\Query\Builder $sub) use ($employeeOrgId, $publishedVersionId): void {
                            $sub->from('organization_closure_paths')
                                ->whereColumn('ancestor_organization_id', 'cafeteria_providers.organization_id')
                                ->where('descendant_organization_id', $employeeOrgId)
                                ->where('depth', '>', 0)
                                ->when($publishedVersionId !== null, fn ($s) => $s->where('hierarchy_version_id', $publishedVersionId));
                        });
              });
        });
    }

    /**
     * Scope a query to only return employees that belong to the provider's institution scope.
     *
     * @param  Builder<\App\Models\Employee>  $query
     * @return Builder<\App\Models\Employee>
     */
    public function filterEmployeesForProvider(CafeteriaProvider $provider, Builder $query): Builder
    {
        if ($provider->organization_id === null) {
            return $query;
        }

        $publishedVersionId = $this->publishedVersionId();

        if (($provider->assigned_scope_type ?? 'self') === 'self') {
            return $query->whereHas('currentAssignment', fn ($q) => $q->where('organization_id', $provider->organization_id));
        }

        // subtree: include the org itself + all descendants
        $descendantIds = OrganizationClosurePath::query()
            ->where('ancestor_organization_id', $provider->organization_id)
            ->when($publishedVersionId !== null, fn ($q) => $q->where('hierarchy_version_id', $publishedVersionId))
            ->pluck('descendant_organization_id')
            ->all();

        $orgIds = array_unique(array_merge([$provider->organization_id], $descendantIds));

        return $query->whereHas('currentAssignment', fn ($q) => $q->whereIn('organization_id', $orgIds));
    }

    private function isDescendant(string $childOrgId, string $ancestorOrgId): bool
    {
        $publishedVersionId = $this->publishedVersionId();

        return OrganizationClosurePath::query()
            ->where('ancestor_organization_id', $ancestorOrgId)
            ->where('descendant_organization_id', $childOrgId)
            ->where('depth', '>', 0)
            ->when($publishedVersionId !== null, fn ($q) => $q->where('hierarchy_version_id', $publishedVersionId))
            ->exists();
    }

    private function publishedVersionId(): ?string
    {
        return HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published)
            ->orderByDesc('effective_from')
            ->value('id');
    }
}
