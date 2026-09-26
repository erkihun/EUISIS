<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServicePolicy;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationCafeteriaAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single place that decides which cafeteria policy governs a scan.
 *
 *   employee assignment ON the service date → employee organization
 *   scanned cafeteria → its provider (payee) → its network
 *   organization access to that network, and to that location
 *   service assignment (organization ↔ provider) in effect
 *   policy of the EMPLOYEE organization, most specific scope first:
 *       organization + cafeteria → organization + network → organization + provider
 *
 * The cafeteria's own "primary organization" plays no part, and there is no
 * global or default financial fallback: no binding policy, no transaction.
 */
class CafeteriaPolicyResolver
{
    public function resolve(Employee $employee, CafeteriaProvider $cafeteria, Carbon $serviceDate): CafeteriaPolicyResolution
    {
        $date = $serviceDate->copy()->startOfDay();
        $cafeteria->loadMissing(['provider', 'network']);

        $employeeAssignment = $this->employeeAssignmentOn($employee, $date);
        if ($employeeAssignment === null) {
            return new CafeteriaPolicyResolution('no_employee_assignment', $cafeteria);
        }

        $fail = fn (string $reason, array $found = []): CafeteriaPolicyResolution => new CafeteriaPolicyResolution(
            $reason, $cafeteria, $employeeAssignment, ...$found,
        );

        if (! $cafeteria->is_active || $cafeteria->trashed()) {
            return $fail('cafeteria_inactive');
        }

        $provider = $cafeteria->provider;
        if ($provider === null || $provider->status !== 'active' || $provider->trashed()) {
            return $fail('provider_inactive');
        }

        $network = $cafeteria->network;
        if ($network === null || ! $network->isActive() || $network->provider_id !== $provider->id) {
            return $fail('cafeteria_not_in_network', ['provider' => $provider]);
        }

        $organizationId = $employeeAssignment->organization_id;

        $access = OrganizationCafeteriaAccess::query()
            ->where('organization_id', $organizationId)
            ->where('cafeteria_service_network_id', $network->id)
            ->effectiveOn($date)
            ->with('locationExceptions')
            ->orderByDesc('effective_from')
            ->first();

        if ($access === null) {
            return $fail('no_cafeteria_access', ['provider' => $provider, 'network' => $network]);
        }

        if (! $this->locationAllowed($access, $cafeteria, $date)) {
            return $fail('location_not_allowed', ['provider' => $provider, 'network' => $network, 'access' => $access]);
        }

        $serviceAssignments = $this->scopedToLocation(
            CafeteriaServiceAssignment::query()
                ->where('organization_id', $organizationId)
                ->where('provider_id', $provider->id)
                ->effectiveOn($date),
            $cafeteria,
        )->get();

        if ($serviceAssignments->isEmpty()) {
            return $fail('no_service_assignment', ['provider' => $provider, 'network' => $network, 'access' => $access]);
        }

        $policy = $this->mostSpecific($this->scopedToLocation(
            CafeteriaServicePolicy::query()
                ->where('organization_id', $organizationId)
                ->where('provider_id', $provider->id)
                ->bindingOn($date)
                // A policy only binds while its own assignment is in effect.
                ->whereIn('cafeteria_service_assignment_id', $serviceAssignments->modelKeys()),
            $cafeteria,
        )->get());

        if ($policy === null) {
            return $fail('no_active_policy', ['provider' => $provider, 'network' => $network, 'access' => $access]);
        }

        return new CafeteriaPolicyResolution(
            null,
            $cafeteria,
            $employeeAssignment,
            $provider,
            $network,
            $access,
            $serviceAssignments->firstWhere('id', $policy->cafeteria_service_assignment_id),
            $policy,
        );
    }

    /**
     * The assignment in force on the date — so a transfer changes the policy
     * from its effective date, and never retroactively.
     */
    public function employeeAssignmentOn(Employee $employee, Carbon $date): ?EmployeeAssignment
    {
        return EmployeeAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('is_current')
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Location rule inside an accessible network. An explicit per-location
     * exception wins; otherwise the primary cafeteria is always allowed, and
     * other locations only when cross-location usage is enabled.
     */
    public function locationAllowed(OrganizationCafeteriaAccess $access, CafeteriaProvider $cafeteria, Carbon $date): bool
    {
        if ($cafeteria->cafeteria_service_network_id !== $access->cafeteria_service_network_id) {
            return false;
        }

        $exception = $access->locationExceptions
            ->filter(fn ($row) => $row->cafeteria_id === $cafeteria->id && $row->coversDate($date))
            ->sortByDesc('effective_from')
            ->first();

        if ($exception !== null) {
            return $exception->is_allowed;
        }

        return $access->primary_cafeteria_id === $cafeteria->id || $access->allow_cross_location_usage;
    }

    /**
     * Rows that apply at this cafeteria: scoped to it, to its network, or
     * provider-wide.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopedToLocation(Builder $query, CafeteriaProvider $cafeteria): Builder
    {
        return $query->where(function (Builder $scope) use ($cafeteria): void {
            $scope->where('cafeteria_id', $cafeteria->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('cafeteria_id')->where('cafeteria_service_network_id', $cafeteria->cafeteria_service_network_id))
                ->orWhere(fn (Builder $q) => $q->whereNull('cafeteria_id')->whereNull('cafeteria_service_network_id'));
        });
    }

    /** @param Collection<int, CafeteriaServicePolicy> $policies */
    private function mostSpecific(Collection $policies): ?CafeteriaServicePolicy
    {
        $rank = [
            CafeteriaServicePolicy::SCOPE_CAFETERIA => 0,
            CafeteriaServicePolicy::SCOPE_NETWORK => 1,
            CafeteriaServicePolicy::SCOPE_PROVIDER => 2,
        ];

        return $policies
            ->sortBy(fn (CafeteriaServicePolicy $policy): string => $rank[$policy->scopeLevel()].'|'.(9999 - $policy->version_no))
            ->first();
    }
}
