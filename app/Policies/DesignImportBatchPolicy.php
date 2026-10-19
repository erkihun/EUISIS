<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DesignImportBatch;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/** Permission says WHAT; OrganizationScopeService says WHERE. Committing is a separate grant from uploading. */
readonly class DesignImportBatchPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('design-imports.viewAny');
    }

    public function view(User $user, DesignImportBatch $batch): bool
    {
        return $user->can('design-imports.viewAny') && $this->scope->canAccess($user, $batch->organization_id);
    }

    /** The target organization is checked by the request. */
    public function create(User $user): bool
    {
        return $user->can('design-imports.create');
    }

    public function update(User $user, DesignImportBatch $batch): bool
    {
        return $user->can('design-imports.create') && $this->scope->canAccess($user, $batch->organization_id);
    }

    public function commit(User $user, DesignImportBatch $batch): bool
    {
        return $user->can('design-imports.commit') && $this->scope->canAccess($user, $batch->organization_id);
    }
}
