<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Model;

/** Powers and functions share one permission group; both carry organization_id. */
readonly class OrganizationFunctionPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('organization-functions.viewAny');
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can('organization-functions.viewAny') && $this->scope->canAccess($user, $record->getAttribute('organization_id'));
    }

    public function update(User $user, Model $record): bool
    {
        return $user->can('organization-functions.manage') && $this->scope->canAccess($user, $record->getAttribute('organization_id'));
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }
}
