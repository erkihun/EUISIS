<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/** Organizations (ADR 0009): the organization itself must be inside the actor's scope. */
final class OrganizationPolicy
{
    use ChecksOrganizationScope;

    public function view(User $user, Organization $organization): bool
    {
        return $this->permits($user, 'organizations.view', (string) $organization->getKey());
    }

    /** A new organization sits under one the actor holds (a city-wide actor may add a top one). */
    public function create(User $user, ?string $parentId = null): bool
    {
        return $this->permits($user, 'organizations.create', $parentId);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->permits($user, 'organizations.update', (string) $organization->getKey(), $organization->merged_into_id === null && ! $organization->trashed());
    }

    public function archive(User $user, Organization $organization): bool
    {
        return $this->permits($user, 'organizations.archive', (string) $organization->getKey(), $organization->status === Organization::ACTIVE);
    }

    /** Deleting is city-wide (the permission counts only for a city-wide account) and only for an unused organization (DeleteOrganization checks). */
    public function delete(User $user, Organization $organization): bool
    {
        return $this->permits($user, 'organizations.delete', (string) $organization->getKey(), ! $organization->trashed());
    }
}
