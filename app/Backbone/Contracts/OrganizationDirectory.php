<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\OrganizationData;
use App\Models\User;

/**
 * Organizations by identity. `find` and `findByCode` answer internal
 * questions; the `…For` methods answer for a person and return nothing
 * outside that person's organization scope (OrganizationScopeService).
 */
interface OrganizationDirectory
{
    public function find(string $organizationUuid): ?OrganizationData;

    public function findByCode(string $code): ?OrganizationData;

    /** The organization, or null when it does not exist or lies outside the viewer's scope. */
    public function findFor(User $viewer, string $organizationUuid): ?OrganizationData;

    /**
     * Active organizations the viewer may see, by code, at most $limit.
     *
     * @return list<OrganizationData>
     */
    public function activeFor(User $viewer, int $limit = 100): array;
}
