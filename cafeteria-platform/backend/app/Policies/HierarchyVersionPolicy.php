<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\ManageHierarchyVersion;
use App\Models\HierarchyVersion;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/**
 * Hierarchy versions (ADR 0009): any reader sees them; preparing, approving
 * and publishing are city-wide permissions (they count only for a city-wide
 * account), each from the version's own status.
 */
final class HierarchyVersionPolicy
{
    use ChecksOrganizationScope;

    public function view(User $user, HierarchyVersion $version): bool
    {
        return $user->is_active && $user->hasOrganizationScope() && $user->can('hierarchy-versions.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->can('hierarchy-versions.manage');
    }

    public function update(User $user, HierarchyVersion $version): bool
    {
        return $this->create($user) && $version->status === 'draft';
    }

    public function transition(User $user, HierarchyVersion $version, string $transition): bool
    {
        $from = match ($transition) {
            'review' => ['draft'],
            'approve' => ['review'],
            'reject' => ['review', 'approved'],
            'publish' => ['approved'],
            default => [],
        };

        return $user->is_active && $user->can(ManageHierarchyVersion::permissionFor($transition)) && in_array($version->status, $from, true);
    }
}
