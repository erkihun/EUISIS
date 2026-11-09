<?php

declare(strict_types=1);

namespace Modules\Reception\Application\Services;

use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReceptionAccess
{
    public function __construct(private OrganizationScopeService $scope) {}

    public function authorize(User $user, string $permission, string $organizationId): void
    {
        abort_unless($this->scope->canExercisePermission($user, $permission, $organizationId), 403);
    }

    /** @param iterable<string> $organizationIds */
    public function authorizeAny(User $user, string $permission, iterable $organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            if ($this->scope->canExercisePermission($user, $permission, $organizationId)) {
                return;
            }
        }

        abort(403);
    }

    public function scope(Builder $query, User $user, string $column = 'organization_id'): Builder
    {
        return $this->scope->applyOrganizationScope($query, $user, $column);
    }
}
