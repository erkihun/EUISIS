<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServiceNetwork;
use App\Models\CafeteriaServicePolicy;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationCafeteriaAccess;
use App\Models\Provider;

/**
 * Everything the resolver established for one employee at one cafeteria on
 * one service date. `reason` is a stable denial code when unresolved.
 *
 * Ownership is explicit: the EMPLOYEE organization owns entitlement and pays;
 * the scanned CAFETERIA is where service happened; its PROVIDER is the payee.
 */
final class CafeteriaPolicyResolution
{
    public function __construct(
        public readonly ?string $reason,
        public readonly CafeteriaProvider $cafeteria,
        public readonly ?EmployeeAssignment $employeeAssignment = null,
        public readonly ?Provider $provider = null,
        public readonly ?CafeteriaServiceNetwork $network = null,
        public readonly ?OrganizationCafeteriaAccess $access = null,
        public readonly ?CafeteriaServiceAssignment $serviceAssignment = null,
        public readonly ?CafeteriaServicePolicy $policy = null,
    ) {}

    public function resolved(): bool
    {
        return $this->reason === null && $this->policy !== null;
    }

    /** The billing and entitlement owner. */
    public function employeeOrganizationId(): ?string
    {
        return $this->employeeAssignment?->organization_id;
    }

    /** @return array<string, mixed> identifiers for audit and results — no personal data */
    public function toAuditArray(): array
    {
        return array_filter([
            'reason' => $this->reason,
            'employee_organization_id' => $this->employeeOrganizationId(),
            'cafeteria_id' => $this->cafeteria->id,
            'provider_id' => $this->provider?->id,
            'cafeteria_service_network_id' => $this->network?->id,
            'organization_cafeteria_access_id' => $this->access?->id,
            'cafeteria_service_assignment_id' => $this->serviceAssignment?->id,
            'cafeteria_service_policy_id' => $this->policy?->id,
            'cafeteria_policy_version' => $this->policy?->version_no,
        ], fn ($value) => $value !== null);
    }
}
