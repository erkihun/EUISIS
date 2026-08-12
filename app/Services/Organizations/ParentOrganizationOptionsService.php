<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Collection;

readonly class ParentOrganizationOptionsService
{
    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    /**
     * @return array{
     *     options: array<int, array<string, mixed>>,
     *     selected: array<string, mixed>|null
     * }
     */
    public function resolve(
        User $user,
        ?string $search = null,
        ?string $selectedId = null,
        ?string $hierarchyVersionId = null,
        ?string $currentOrganizationId = null,
        int $limit = 25,
    ): array {
        $accessibleOrganizationIds = $this->organizationScopeService->accessibleOrganizationIds($user);

        $query = Organization::query()
            ->with('type:id,name_en,code')
            ->where('status', OrganizationStatus::Active->value)
            ->when(
                ! $user->hasRole('Super Admin') && ! $user->hasRole('City Admin'),
                fn ($builder) => $builder->whereIn('id', $accessibleOrganizationIds)
            )
            ->when($currentOrganizationId !== null, fn ($builder) => $builder->whereKeyNot($currentOrganizationId))
            ->when(
                $search !== null && $search !== '',
                fn ($builder) => $builder->where(function ($inner) use ($search): void {
                    $inner->where('code', ci_like_operator(), '%'.$search.'%')
                        ->orWhere('name_en', ci_like_operator(), '%'.$search.'%')
                        ->orWhere('name_am', ci_like_operator(), '%'.$search.'%');
                })
            )
            ->orderBy('name_en')
            ->limit($limit);

        $organizations = $query->get([
            'id',
            'organization_type_id',
            'code',
            'name_en',
            'name_am',
            'status',
        ]);

        $selectedOrganization = null;

        if ($selectedId !== null) {
            $selectedOrganization = Organization::query()
                ->with('type:id,name_en,code')
                ->find($selectedId, [
                    'id',
                    'organization_type_id',
                    'code',
                    'name_en',
                    'name_am',
                    'status',
                ]);

            if ($selectedOrganization !== null && ! $organizations->contains('id', $selectedOrganization->id)) {
                $organizations->prepend($selectedOrganization);
            }
        }

        $organizations = $organizations->unique('id')->values();

        $mapped = $this->mapOptions($organizations, $user, $hierarchyVersionId);
        $selected = $selectedOrganization === null
            ? null
            : collect($mapped)->firstWhere('id', $selectedOrganization->id);

        return [
            'options' => $mapped,
            'selected' => $selected,
        ];
    }

    /**
     * @param  Collection<int, Organization>  $organizations
     * @return array<int, array<string, mixed>>
     */
    private function mapOptions(Collection $organizations, User $user, ?string $hierarchyVersionId): array
    {
        if ($organizations->isEmpty()) {
            return [];
        }

        $pathsByOrganization = $this->buildHierarchyHints(
            $organizations->pluck('id')->all(),
            $hierarchyVersionId,
        );

        return $organizations
            ->map(function (Organization $organization) use ($user, $pathsByOrganization): array {
                $hint = $pathsByOrganization[$organization->id] ?? ['depth' => null, 'parent_path' => null];

                return [
                    'id' => $organization->id,
                    'code' => $organization->code,
                    'name_en' => $organization->name_en,
                    'name_am' => $organization->name_am,
                    'status' => $organization->status instanceof \BackedEnum
                        ? $organization->status->value
                        : (string) $organization->status,
                    'organization_type' => $organization->type ? [
                        'code' => $organization->type->code,
                        'name_en' => $organization->type->name_en,
                    ] : null,
                    'depth' => $hint['depth'],
                    'parent_path' => $hint['parent_path'],
                    'can_create_child' => $user->can('createChild', $organization),
                ];
            })
            ->sortBy([
                ['can_create_child', 'desc'],
                ['name_en', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $organizationIds
     * @return array<string, array{depth: int|null, parent_path: string|null}>
     */
    private function buildHierarchyHints(array $organizationIds, ?string $hierarchyVersionId): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $versionId = $this->resolveHierarchyVersionId($hierarchyVersionId);

        if ($versionId === null) {
            return [];
        }

        $paths = OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $versionId)
            ->whereIn('descendant_organization_id', $organizationIds)
            ->orderByDesc('depth')
            ->get([
                'ancestor_organization_id',
                'descendant_organization_id',
                'depth',
            ]);

        if ($paths->isEmpty()) {
            return [];
        }

        $ancestorIds = $paths->pluck('ancestor_organization_id')->unique()->all();

        $ancestorNames = Organization::query()
            ->whereIn('id', $ancestorIds)
            ->pluck('name_en', 'id');

        $hints = [];

        foreach ($paths->groupBy('descendant_organization_id') as $descendantId => $descendantPaths) {
            $depth = $descendantPaths->max('depth');

            $parentPath = $descendantPaths
                ->filter(fn (OrganizationClosurePath $path): bool => $path->depth > 0)
                ->sortByDesc('depth')
                ->map(fn (OrganizationClosurePath $path): ?string => $ancestorNames->get($path->ancestor_organization_id))
                ->filter()
                ->implode(' / ');

            $hints[$descendantId] = [
                'depth' => $depth,
                'parent_path' => $parentPath !== '' ? $parentPath : null,
            ];
        }

        return $hints;
    }

    private function resolveHierarchyVersionId(?string $hierarchyVersionId): ?string
    {
        if ($hierarchyVersionId !== null) {
            return $hierarchyVersionId;
        }

        return HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published->value)
            ->latest('approval_date')
            ->value('id');
    }
}
