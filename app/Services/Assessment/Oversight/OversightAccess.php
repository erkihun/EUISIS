<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Oversight authorization: permission + organization scope + (for line
 * managers) unit scope. Every page, drill-down and export goes through here;
 * nothing relies on the frontend hiding links.
 */
class OversightAccess
{
    /** @var array<int, OversightScope> */
    private array $cache = [];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function scopeFor(User $user): OversightScope
    {
        return $this->cache[$user->id] ??= $this->resolve($user);
    }

    public function canView(User $user): bool
    {
        return $user->canAny(['assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_unit']);
    }

    public function canSeeOrganization(User $user, ?string $organizationId): bool
    {
        return $this->scopeFor($user)->allowsOrganization($organizationId);
    }

    /** Abort unless the organization (and unit, when given) is inside the user's oversight scope. */
    public function authorizeOrganization(User $user, ?string $organizationId, ?string $unitId = null): void
    {
        $scope = $this->scopeFor($user);
        abort_unless($scope->allowsOrganization($organizationId) && ($unitId === null || $scope->allowsUnit($unitId)), 403);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function resolve(User $user): OversightScope
    {
        if ($user->canAny(['assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions'])) {
            return new OversightScope($this->scope->isUnrestricted($user) ? null : $this->scope->allowedOrganizationIds($user));
        }

        if ($user->can('assessment_oversight.view_unit')) {
            $assignment = $user->employee?->currentAssignment;
            if ($assignment?->organization_unit_id === null) {
                return new OversightScope([]);
            }

            return new OversightScope([$assignment->organization_id], $this->subtree($assignment->organization_unit_id));
        }

        return new OversightScope([]);
    }

    /** @return array<int, string> the unit and every unit below it */
    private function subtree(string $unitId): array
    {
        $ids = [$unitId];
        $frontier = [$unitId];
        for ($depth = 0; $frontier !== [] && $depth < 50; $depth++) {
            $frontier = OrganizationUnit::query()->whereIn('parent_unit_id', $frontier)->pluck('id')->map(fn ($id): string => (string) $id)->diff($ids)->values()->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
