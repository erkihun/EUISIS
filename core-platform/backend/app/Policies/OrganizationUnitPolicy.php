<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationUnit;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/** Organization units (ADR 0009): within their organization's scope, by state. */
final class OrganizationUnitPolicy
{
    use ChecksOrganizationScope;

    public function view(User $user, OrganizationUnit $unit): bool
    {
        return $this->permits($user, 'organization-units.viewAny', (string) $unit->organization_id);
    }

    public function update(User $user, OrganizationUnit $unit): bool
    {
        return $this->permits($user, 'organization-units.update', (string) $unit->organization_id, ! $unit->trashed());
    }

    public function archive(User $user, OrganizationUnit $unit): bool
    {
        return $this->permits($user, 'organization-units.archive', (string) $unit->organization_id, ! $unit->trashed() && $unit->status === 'active');
    }

    public function restore(User $user, OrganizationUnit $unit): bool
    {
        return $this->permits($user, 'organization-units.restore', (string) $unit->organization_id, $unit->trashed() || $unit->status !== 'active');
    }
}
