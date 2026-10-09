<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

readonly class OrganizationTreeService
{
    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function editableOrganizationOptions(
        User $user,
        ?string $search = null,
        ?string $selectedId = null,
        int $limit = 25,
    ): Collection {
        $query = Organization::query()
            ->with('type:id,name_en,name_am,code')
            ->where('status', OrganizationStatus::Active);

        if (! $user->hasRole('Super Admin') && ! $user->hasRole('City Admin')) {
            $query->whereIn('id', $this->organizationScopeService->accessibleOrganizationIds($user));
        }

        $query->when($search, function ($organizationQuery, string $term): void {
            $organizationQuery->where(function ($innerQuery) use ($term): void {
                $innerQuery
                    ->where('code', ci_like_operator(), "%{$term}%")
                    ->orWhere('name_en', ci_like_operator(), "%{$term}%")
                    ->orWhere('name_am', ci_like_operator(), "%{$term}%");
            });
        });

        $options = $query
            ->orderBy('name_en')
            ->limit($limit)
            ->get(['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id'])
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'status' => $organization->status->value,
                'type' => $organization->type ? [
                    'code' => $organization->type->code,
                    'name_en' => $organization->type->name_en,
                    'name_am' => $organization->type->name_am,
                ] : null,
            ]);

        if ($selectedId !== null && ! $options->contains('id', $selectedId)) {
            $selectedOrganization = Organization::query()
                ->with('type:id,name_en,name_am,code')
                ->whereKey($selectedId)
                ->where('status', OrganizationStatus::Active)
                ->first(['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id']);

            if ($selectedOrganization !== null && $this->organizationScopeService->canAccessOrganization($user, $selectedId)) {
                $options->prepend([
                    'id' => $selectedOrganization->id,
                    'code' => $selectedOrganization->code,
                    'name_en' => $selectedOrganization->name_en,
                    'name_am' => $selectedOrganization->name_am,
                    'status' => $selectedOrganization->status->value,
                    'type' => $selectedOrganization->type ? [
                        'code' => $selectedOrganization->type->code,
                        'name_en' => $selectedOrganization->type->name_en,
                        'name_am' => $selectedOrganization->type->name_am,
                    ] : null,
                ]);
            }
        }

        return $options->unique('id')->values();
    }

    public function relationshipTypeOptions(): array
    {
        return array_map(
            static fn (OrganizationRelationshipType $type) => $type->value,
            OrganizationRelationshipType::cases(),
        );
    }

    public function assertDraftVersion(HierarchyVersion $hierarchyVersion): void
    {
        if ($hierarchyVersion->status !== HierarchyVersionStatus::Draft) {
            throw ValidationException::withMessages([
                'hierarchy_version_id' => __('hierarchy-versions.only_draft_can_be_published'),
            ]);
        }
    }

    public function validateEdgeMutation(
        HierarchyVersion $hierarchyVersion,
        User $actor,
        string $parentOrganizationId,
        string $childOrganizationId,
        ?string $ignoreEdgeId = null,
    ): void {
        $this->assertDraftVersion($hierarchyVersion);

        if ($parentOrganizationId === $childOrganizationId) {
            throw ValidationException::withMessages([
                'child_organization_id' => __('organizations.parent_and_child_cannot_match'),
            ]);
        }

        if (! $this->organizationScopeService->canAccessOrganization($actor, $parentOrganizationId)) {
            throw ValidationException::withMessages([
                'parent_organization_id' => __('organizations.parent_organization_outside_scope'),
            ]);
        }

        if (! $this->organizationScopeService->canAccessOrganization($actor, $childOrganizationId)) {
            throw ValidationException::withMessages([
                'child_organization_id' => __('organizations.parent_organization_outside_scope'),
            ]);
        }

        $edges = OrganizationEdge::query()
            ->where('hierarchy_version_id', $hierarchyVersion->id)
            ->when($ignoreEdgeId, fn ($query, string $edgeId) => $query->whereKeyNot($edgeId))
            ->get(['parent_organization_id', 'child_organization_id']);

        $duplicatePair = $edges->contains(
            fn (OrganizationEdge $edge) => $edge->parent_organization_id === $parentOrganizationId
                && $edge->child_organization_id === $childOrganizationId,
        );

        if ($duplicatePair) {
            throw ValidationException::withMessages([
                'child_organization_id' => __('organizations.duplicateEdge'),
            ]);
        }

        $graph = $edges
            ->groupBy('parent_organization_id')
            ->map(fn (Collection $group) => $group->pluck('child_organization_id')->values()->all())
            ->all();

        $graph[$parentOrganizationId] ??= [];
        $graph[$parentOrganizationId][] = $childOrganizationId;

        if ($this->hasCycle($graph)) {
            throw ValidationException::withMessages([
                'child_organization_id' => __('organizations.circularHierarchy'),
            ]);
        }
    }

    private function hasCycle(array $graph): bool
    {
        $visited = [];
        $active = [];

        $visit = function (string $node) use (&$visit, &$visited, &$active, $graph): bool {
            if (($active[$node] ?? false) === true) {
                return true;
            }

            if (($visited[$node] ?? false) === true) {
                return false;
            }

            $visited[$node] = true;
            $active[$node] = true;

            foreach ($graph[$node] ?? [] as $childNode) {
                if ($visit($childNode)) {
                    return true;
                }
            }

            $active[$node] = false;

            return false;
        };

        foreach (array_keys($graph) as $node) {
            if ($visit((string) $node)) {
                return true;
            }
        }

        return false;
    }
}
