<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LegalSource;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Permission says WHAT; OrganizationScopeService says WHERE. A city-level
 * source (no organization) is readable by everyone with the view permission
 * and written only by the catalog manager.
 */
readonly class LegalSourcePolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('legal-sources.viewAny');
    }

    public function view(User $user, LegalSource $source): bool
    {
        return $user->can('legal-sources.view') && $this->where($user, $source, write: false);
    }

    /** Creating is checked against the target organization by the request. */
    public function create(User $user): bool
    {
        return $user->can('legal-sources.create') || $user->can('legal-sources.manageCatalog');
    }

    public function update(User $user, LegalSource $source): bool
    {
        return $source->isCityLevel()
            ? $user->can('legal-sources.manageCatalog')
            : $user->can('legal-sources.update') && $this->where($user, $source, write: true);
    }

    public function delete(User $user, LegalSource $source): bool
    {
        return $this->update($user, $source);
    }

    public function manageAttachments(User $user, LegalSource $source): bool
    {
        return $this->update($user, $source);
    }

    public function download(User $user, LegalSource $source): bool
    {
        return $this->view($user, $source);
    }

    private function where(User $user, LegalSource $source, bool $write): bool
    {
        if ($source->isCityLevel()) {
            return ! $write;
        }

        return $this->scope->canAccess($user, $source->organization_id);
    }
}
