<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Position;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/** Positions (ADR 0009): within their organization's scope, by state. */
final class PositionPolicy
{
    use ChecksOrganizationScope;

    public function view(User $user, Position $position): bool
    {
        return $this->permits($user, 'positions.viewAny', (string) $position->organization_id);
    }

    public function update(User $user, Position $position): bool
    {
        return $this->permits($user, 'positions.update', (string) $position->organization_id, ! $position->trashed());
    }

    public function archive(User $user, Position $position): bool
    {
        return $this->permits($user, 'positions.archive', (string) $position->organization_id, ! $position->trashed());
    }

    public function restore(User $user, Position $position): bool
    {
        return $this->permits($user, 'positions.restore', (string) $position->organization_id, $position->trashed());
    }
}
