<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Models\OrganizationUnit;

/**
 * The owner and host organizations of a position, for the {OWNER_ORG_CODE}
 * and {HOST_ORG_CODE} tokens. EUISIS derives a host from unit relationships;
 * the Core has no hosted units, so the owner is the unit's organization (or
 * the organization given) and there is never a host.
 */
class PositionCodeContextResolver
{
    /** @return array{owner_organization_id: string|null, host_organization_id: string|null} */
    public function resolve(?string $organizationId, ?string $organizationUnitId): array
    {
        $owner = $organizationId ?: null;
        if (! empty($organizationUnitId)) {
            $unitOrganization = OrganizationUnit::query()->whereKey($organizationUnitId)->value('organization_id');
            $owner = $unitOrganization !== null && $unitOrganization !== '' ? (string) $unitOrganization : $owner;
        }

        return ['owner_organization_id' => $owner, 'host_organization_id' => null];
    }

    /** @return array{owner_organization_id: string|null, host_organization_id: string|null} */
    public function validateForGeneration(?string $organizationId, ?string $organizationUnitId, string $field = 'job_position_code'): array
    {
        return $this->resolve($organizationId, $organizationUnitId);
    }
}
