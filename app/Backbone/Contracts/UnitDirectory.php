<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\OrganizationUnitData;
use App\Models\User;

/** Organizational units by identity and by organization; the `…For` methods respect the viewer's scope. */
interface UnitDirectory
{
    public function find(string $unitUuid): ?OrganizationUnitData;

    public function findFor(User $viewer, string $unitUuid): ?OrganizationUnitData;

    /**
     * The organization's units, active ones only unless asked otherwise.
     *
     * @return list<OrganizationUnitData>
     */
    public function forOrganization(string $organizationUuid, bool $activeOnly = true): array;
}
