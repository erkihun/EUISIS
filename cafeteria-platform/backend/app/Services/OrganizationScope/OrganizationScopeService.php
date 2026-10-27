<?php

declare(strict_types=1);

namespace App\Services\OrganizationScope;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The EUISIS organization-scope service, as the copied cafeteria code calls
 * it, answered by the platform's own scope (App\Services\OrganizationScope):
 * a city-wide account sees everything, a scoped account its organization and
 * the organizations under it in the mirrored hierarchy.
 */
final class OrganizationScopeService
{
    public function __construct(private readonly OrganizationScope $scope) {}

    public function isUnrestricted(User $user): bool
    {
        return $this->scope->organizationIds($user) === null;
    }

    /** @return list<string> empty for an unrestricted account: callers check isUnrestricted() first */
    public function allowedOrganizationIds(User $user): array
    {
        return array_values(array_map('strval', $this->scope->organizationIds($user) ?? []));
    }

    /** @return Collection<int, string> every organization the account may read */
    public function accessibleOrganizationIds(User $user): Collection
    {
        $ids = $this->scope->organizationIds($user);

        return collect($ids ?? Organization::query()->pluck('id')->map(fn ($id): string => (string) $id)->all())->values();
    }

    public function canAccessOrganization(User $user, ?string $organizationId): bool
    {
        return $this->scope->allows($user, $organizationId);
    }

    /** The canonical rule: an employee is where their current assignment is. */
    public function canAccessEmployee(User $user, Employee $employee): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }
        $organizationId = $employee->currentAssignment?->organization_id;

        return $organizationId !== null && $this->scope->allows($user, (string) $organizationId);
    }

    /** The employees a scoped account may see (those placed in its organizations), or null for an unrestricted one. */
    public function employeeIdsInScope(User $user): ?Builder
    {
        $ids = $this->scope->organizationIds($user);
        if ($ids === null) {
            return null;
        }

        return EmployeeAssignment::query()->where('is_current', true)->whereIn('organization_id', $ids)->select('employee_id');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyOrganizationScope($query, User $user, string $column = 'organization_id')
    {
        return $this->scope->apply($query, $user, $column);
    }
}
