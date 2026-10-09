<?php

declare(strict_types=1);

namespace App\Services\InstitutionOffices;

use App\Models\InstitutionOffice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class InstitutionOfficeScopeService
{
    public function __construct(
        private readonly InstitutionOfficeTreeService $treeService,
    ) {}

    /**
     * Check whether a given office is within the user's accessible scope.
     */
    public function officeInScope(InstitutionOffice $office, User $user): bool
    {
        if ($user->can('super-admin')) {
            return true;
        }

        return in_array($office->id, $this->scopedOfficeIds($user), true);
    }

    /**
     * Return all office IDs accessible to the user.
     *
     * @return string[]
     */
    public function scopedOfficeIds(User $user): array
    {
        if ($user->can('super-admin')) {
            return InstitutionOffice::query()->pluck('id')->all();
        }

        // Determine accessible institution IDs from user's org scope
        $accessibleOrgIds = $user->accessibleOrganizationIds();

        if (empty($accessibleOrgIds)) {
            return [];
        }

        $offices = InstitutionOffice::query()
            ->whereIn('institution_id', $accessibleOrgIds)
            ->get(['id', 'institution_id', 'parent_office_id', 'assigned_scope_type']);

        $ids = [];

        foreach ($offices as $office) {
            $ids[] = $office->id;
            if ($office->assigned_scope_type === 'subtree') {
                $ids = array_merge($ids, $this->treeService->getDescendantOfficeIds($office));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Apply a scope filter to a query so only accessible offices are returned.
     */
    public function filterScopedOffices(Builder $query, User $user): Builder
    {
        if ($user->can('super-admin')) {
            return $query;
        }

        $ids = $this->scopedOfficeIds($user);

        return $query->whereIn('id', $ids);
    }

    /**
     * Filter employee assignments to those within the user's office scope.
     */
    public function filterEmployeeAssignments(Builder $query, User $user): Builder
    {
        if ($user->can('super-admin')) {
            return $query;
        }

        $accessibleOrgIds = $user->accessibleOrganizationIds();

        if (empty($accessibleOrgIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('organization_id', $accessibleOrgIds);
    }
}
