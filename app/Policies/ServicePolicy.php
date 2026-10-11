<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Service;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/** Permission says WHAT; OrganizationScopeService says WHERE. */
readonly class ServicePolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('service-catalog.viewAny');
    }

    public function view(User $user, Service $service): bool
    {
        return $user->can('service-catalog.view') && $this->scope->canAccess($user, $service->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('service-catalog.create');
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can('service-catalog.update') && $this->scope->canAccess($user, $service->organization_id);
    }

    public function version(User $user, Service $service): bool
    {
        return $user->can('service-catalog.create') && $this->scope->canAccess($user, $service->organization_id);
    }

    public function publish(User $user, Service $service): bool
    {
        return $user->can('service-catalog.approve') && $this->scope->canAccess($user, $service->organization_id);
    }
}
