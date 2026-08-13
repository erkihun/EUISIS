<?php

declare(strict_types=1);

namespace App\Services\OrganizationScope;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OrganizationScopeService
{
    /**
     * Administrative roles that legitimately span every organization.
     *
     * @var array<int, string>
     */
    public const UNRESTRICTED_ROLES = [
        'Super Admin',
        'System Admin',
        'City Admin',
        'Public Service Bureau Admin',
    ];

    /** @var array<string, Collection> */
    private array $requestCache = [];

    public function __construct(
        private readonly PermissionScopeClassifier $permissionScopeClassifier = new PermissionScopeClassifier,
    ) {}

    /**
     * Authoritative check for "may this user exercise $permission against
     * $organizationId?". Combines three things the spec requires together:
     * the permission grant, the role scope type, and the organization scope.
     *
     * System permissions (roles, permissions, audit logs, code rules, security
     * settings) may be exercised system-wide when granted through a global
     * role. Operational permissions always require organization scope.
     */
    public function canExercisePermission(User $user, string $permission, ?string $organizationId = null): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if ($this->hasUnrestrictedRole($user)) {
            return true;
        }

        // System-module work granted via a global role is not tied to an org.
        if ($this->permissionScopeClassifier->isSystemPermission($permission)
            && $user->hasGlobalPermission($permission)) {
            return true;
        }

        // Everything else is operational: it must resolve inside the scope.
        if ($organizationId === null) {
            return ! $this->isScopedOrganizationalAdmin($user)
                && ! $user->organizationScopes()->exists();
        }

        return $this->canAccessOrganization($user, $organizationId);
    }

    public function canAccessOrganization(User $user, ?string $organizationId): bool
    {
        if ($organizationId === null) {
            return false;
        }

        if ($this->hasUnrestrictedRole($user)) {
            return true;
        }

        if ($this->isScopedOrganizationalAdmin($user)) {
            return $this->accessibleOrganizationIds($user)->contains($organizationId);
        }

        $hasAnyScopeRecord = $user->organizationScopes()->exists();

        // Established convention: no explicit scope record means "not scoped
        // yet", which grants org-wide access. Tightening this would silently
        // lock out every existing role that has no scope rows, so it stays.
        if (! $hasAnyScopeRecord) {
            return true;
        }

        return $this->accessibleOrganizationIds($user)->contains($organizationId);
    }

    public function canAccessEmployee(User $user, Employee $employee): bool
    {
        return $this->canAccessOrganization($user, $employee->currentAssignment?->organization_id);
    }

    // ── Public scope API ─────────────────────────────────────────────────────
    // Thin, explicitly-named wrappers over the resolver above. These are the
    // methods controllers/policies should prefer; they normalise the
    // int|string|Organization argument and express the "manage vs. read"
    // distinction an Organizational Admin needs.

    /**
     * All organization ids the user may reach, as a plain array.
     * Super Admin / City Admin (and unscoped staff) receive every id.
     *
     * @return array<int, string>
     */
    public function allowedOrganizationIds(User $user): array
    {
        return $this->accessibleOrganizationIds($user)->all();
    }

    /**
     * Organization ids explicitly assigned by an active scope, without
     * expanding subtree access to descendant organizations.
     *
     * @return array<int, string>
     */
    public function assignedOrganizationIds(User $user): array
    {
        return $user->organizationScopes()
            ->active()
            ->whereNotNull('organization_id')
            ->pluck('organization_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function mustCreateUnderAssignedOrganization(User $user): bool
    {
        return $this->isScopedOrganizationalAdmin($user);
    }

    public function isScopedOrganizationalAdmin(User $user): bool
    {
        return $user->hasRole('Organizational Admin')
            && ! $user->hasRole('Super Admin')
            && ! $user->hasRole('City Admin');
    }

    public function canCreateOrganizationUnder(User $user, int|string|Organization|null $organization): bool
    {
        $organizationId = $this->normalizeOrganizationId($organization);

        if ($organizationId === null) {
            return false;
        }

        if (! $this->mustCreateUnderAssignedOrganization($user)) {
            return $this->canAccessOrganization($user, $organizationId);
        }

        return in_array($organizationId, $this->allowedOrganizationIds($user), true)
            && in_array($organizationId, $this->assignedOrganizationIds($user), true);
    }

    /**
     * True when the record's organization is inside the user's scope.
     * Accepts an id (string/int) or an Organization instance.
     */
    public function canAccess(User $user, int|string|Organization|null $organization): bool
    {
        return $this->canAccessOrganization($user, $this->normalizeOrganizationId($organization));
    }

    /**
     * Constrain a query to the user's accessible organizations.
     *
     * Unrestricted actors (Super Admin / City Admin, or staff with no explicit
     * scope record) are returned untouched — matching the app-wide convention
     * that an empty scope means "all organizations". Scoped actors get a
     * `whereIn` on the given column (an impossible predicate when the resolved
     * set is empty, so a scoped-but-empty user sees nothing rather than
     * everything).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyOrganizationScope($query, User $user, string $column = 'organization_id')
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $query->whereIn($column, $this->allowedOrganizationIds($user));
    }

    /**
     * Limit a user query to accounts linked to at least one organization the
     * actor may access. Expired/inactive scope records do not grant visibility.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function applyUserScope(Builder $query, User $actor): Builder
    {
        if ($this->isUnrestricted($actor)) {
            return $query;
        }

        $allowed = $this->allowedOrganizationIds($actor);

        return $query->where(function (Builder $scoped) use ($allowed): void {
            $scoped->whereHas(
                'organizationScopes',
                fn (Builder $scopeQuery) => $scopeQuery->active()->whereIn('organization_id', $allowed),
            )->orWhereIn('default_organization_id', $allowed);
        });
    }

    public function canManageUser(User $actor, User $target): bool
    {
        if ($this->isUnrestricted($actor)) {
            return true;
        }

        return $this->applyUserScope(User::query()->whereKey($target), $actor)->exists();
    }

    /**
     * Whether the user may create/update/delete records belonging to the given
     * organization. Identical to read access today (scope is symmetric), but
     * kept separate so a future read-only scoped role can diverge without
     * touching call sites.
     */
    public function canManageWithinScope(User $user, int|string|Organization|null $organization): bool
    {
        return $this->canAccess($user, $organization);
    }

    /**
     * Whether the actor may grant another user access to the given
     * organization. A scoped actor may only delegate organizations inside
     * their own accessible set; Super Admin / City Admin / unscoped staff are
     * unrestricted.
     */
    public function canAssignUserToScope(User $actor, int|string|Organization|null $organization): bool
    {
        $organizationId = $this->normalizeOrganizationId($organization);

        if ($organizationId === null) {
            return false;
        }

        if ($this->isUnrestricted($actor)) {
            return true;
        }

        return in_array($organizationId, $this->allowedOrganizationIds($actor), true);
    }

    /**
     * An actor is "unrestricted" when they are Super Admin / City Admin or
     * carry no explicit organization-scope record. This mirrors the default
     * used by {@see canAccessOrganization()}.
     */
    public function isUnrestricted(User $user): bool
    {
        if ($this->hasUnrestrictedRole($user)) {
            return true;
        }

        if ($this->isScopedOrganizationalAdmin($user)) {
            return false;
        }

        return ! $user->organizationScopes()->exists();
    }

    /**
     * Roles that genuinely see every organization.
     *
     * Deliberately NOT "any role with scope_type = global": a global role only
     * lifts organization scoping for the system modules it administers. A role
     * flagged global that merely holds an operational permission (e.g.
     * reports.view) must still be confined to its assigned organizations,
     * otherwise scope_type becomes a blanket bypass of the whole scoping model.
     */
    public function hasUnrestrictedRole(User $user): bool
    {
        return $user->hasAnyRole(self::UNRESTRICTED_ROLES);
    }

    private function normalizeOrganizationId(int|string|Organization|null $organization): ?string
    {
        if ($organization === null) {
            return null;
        }

        if ($organization instanceof Organization) {
            return (string) $organization->getKey();
        }

        return (string) $organization;
    }

    public function clearCache(?User $user = null): void
    {
        if ($user !== null) {
            foreach (array_keys($this->requestCache) as $key) {
                if (str_starts_with($key, "org_scope.{$user->getKey()}.")) {
                    unset($this->requestCache[$key]);
                }
            }
        } else {
            $this->requestCache = [];
        }
    }

    public function descendantsForOrganization(string $organizationId, ?string $hierarchyVersionId = null): Collection
    {
        $query = OrganizationClosurePath::query()
            ->where('ancestor_organization_id', $organizationId);

        if ($hierarchyVersionId !== null) {
            $query->where('hierarchy_version_id', $hierarchyVersionId);
        }

        return $query
            ->orderBy('depth')
            ->get(['descendant_organization_id', 'depth']);
    }

    /**
     * Flat depth-first traversal for the Organizations index page.
     * Returns every node with depth, parent_id, and children_count so the
     * frontend can render an indented hierarchy without recursive calls.
     * When $allowedOrgIds is provided, only nodes within that set are included.
     *
     * @param  string[]|null  $allowedOrgIds  null means no restriction
     */
    public function buildFlatTreeForIndex(?HierarchyVersion $version, ?array $allowedOrgIds = null): array
    {
        if ($version === null) {
            return [];
        }

        $edges = $version->edges()
            ->get(['parent_organization_id', 'child_organization_id']);

        if ($edges->isEmpty()) {
            return [];
        }

        $allOrgIds = $edges->pluck('parent_organization_id')
            ->merge($edges->pluck('child_organization_id'))
            ->unique()
            ->values();

        if ($allowedOrgIds !== null) {
            $allOrgIds = $allOrgIds->intersect($allowedOrgIds)->values();
        }

        $organizations = Organization::query()
            ->whereIn('id', $allOrgIds)
            ->where('status', '!=', OrganizationStatus::Archived->value)
            ->with('type:id,name_en,name_am,code')
            ->get()
            ->keyBy('id');

        $childrenByParent = $edges->groupBy('parent_organization_id');
        $parentsByChild = $edges->groupBy('child_organization_id');

        // A child may reference a parent that is soft-deleted (or otherwise not
        // in the live/allowed set). Such children must still surface as display
        // roots — otherwise a hierarchy whose root org was soft-deleted collapses
        // to an empty tree, which falsely reads as "no published hierarchy".
        $rootIds = $organizations->keys()
            ->filter(function (string $orgId) use ($parentsByChild, $organizations): bool {
                $parentIds = $parentsByChild->get($orgId, collect())->pluck('parent_organization_id');

                if ($parentIds->isEmpty()) {
                    return true; // genuine root — never appears as a child
                }

                // Promote to root when none of its parents are live/visible.
                return $parentIds->every(fn ($parentId): bool => ! $organizations->has($parentId));
            })
            ->values();

        $flat = [];
        $visited = [];

        $buildFlat = function (string $orgId, int $depth, ?string $parentId) use (
            &$buildFlat, &$flat, &$visited, $organizations, $childrenByParent
        ): void {
            $org = $organizations->get($orgId);
            if ($org === null || isset($visited[$orgId])) {
                return;
            }
            $visited[$orgId] = true;

            $childEdges = $childrenByParent->get($orgId, collect())
                ->filter(fn ($edge): bool => $organizations->has($edge->child_organization_id))
                ->values();

            $flat[] = [
                'id' => $org->id,
                'code' => $org->code,
                'name_en' => $org->name_en,
                'name_am' => $org->name_am,
                'status' => $org->status instanceof \BackedEnum ? $org->status->value : (string) $org->status,
                'effective_from' => $org->effective_from?->toDateString(),
                'effective_to' => $org->effective_to?->toDateString(),
                'depth' => $depth,
                'parent_id' => $parentId,
                'children_count' => $childEdges->count(),
                'type' => $org->type ? [
                    'name_en' => $org->type->name_en,
                    'name_am' => $org->type->name_am,
                    'code' => $org->type->code,
                ] : null,
                'branding_primary_color' => $org->branding_primary_color,
                'logo_url' => $org->logo_url,
            ];

            foreach ($childEdges as $edge) {
                $buildFlat($edge->child_organization_id, $depth + 1, $orgId);
            }
        };

        foreach ($rootIds as $rootId) {
            $buildFlat($rootId, 0, null);
        }

        return $flat;
    }

    public function buildVersionTree(HierarchyVersion $version, ?User $user = null): array
    {
        $edges = $version->edges()
            ->with([
                'parentOrganization:id,organization_type_id,code,name_en,name_am,status,logo_path',
                'parentOrganization.type:id,code,name_en,name_am',
                'childOrganization:id,organization_type_id,code,name_en,name_am,status,logo_path',
                'childOrganization.type:id,code,name_en,name_am',
            ])
            ->get();

        if ($edges->isEmpty()) {
            return [];
        }

        $childIds = $edges->pluck('child_organization_id')->unique();
        $parentIds = $edges->pluck('parent_organization_id')->unique();
        $rootIds = $parentIds->diff($childIds)->values();

        $childrenByParent = $edges->groupBy('parent_organization_id');

        $buildNode = function ($edge, int $depth) use (&$buildNode, $childrenByParent, $user, $version): array {
            $organization = $edge->childOrganization;

            if ($organization === null) {
                return [];
            }

            $children = $childrenByParent
                ->get($organization->id, collect())
                ->map(fn ($childEdge) => $buildNode($childEdge, $depth + 1))
                ->filter()
                ->values()
                ->all();

            return [
                'organization_id' => $organization->id,
                'edge_id' => $edge->id,
                'parent_organization_id' => $edge->parent_organization_id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'organization_type' => $organization->type ? [
                    'code' => $organization->type->code,
                    'name_en' => $organization->type->name_en,
                    'name_am' => $organization->type->name_am,
                ] : null,
                'status' => $organization->status instanceof \BackedEnum ? $organization->status->value : (string) $organization->status,
                'logo_url' => $organization->logo_url,
                'depth' => $depth,
                'child_count' => count($children),
                'relationship_type' => $edge->relationship_type instanceof \BackedEnum ? $edge->relationship_type->value : (string) $edge->relationship_type,
                'effective_from' => $edge->effective_from?->toDateString(),
                'effective_to' => $edge->effective_to?->toDateString(),
                'can' => [
                    'edit' => ($user?->can('update', $edge) ?? false)
                        && $version->status === HierarchyVersionStatus::Draft,
                    'remove' => ($user?->can('delete', $edge) ?? false)
                        && $version->status === HierarchyVersionStatus::Draft,
                    'addChild' => ($user?->can('organization-edges.create') ?? false)
                        && ($user?->can('hierarchy-versions.manageTree') ?? false)
                        && $version->status === HierarchyVersionStatus::Draft,
                ],
                'children' => $children,
            ];
        };

        return $rootIds
            ->map(function (string $rootId) use ($childrenByParent, $user, $version, $buildNode): array {
                $rootOrganization = Organization::query()
                    ->with('type:id,code,name_en,name_am')
                    ->find($rootId, ['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'logo_path']);

                if ($rootOrganization === null) {
                    return [];
                }

                $children = $childrenByParent
                    ->get($rootId, collect())
                    ->map(fn ($edge) => $buildNode($edge, 1))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'organization_id' => $rootOrganization->id,
                    'edge_id' => null,
                    'parent_organization_id' => null,
                    'code' => $rootOrganization->code,
                    'name_en' => $rootOrganization->name_en,
                    'name_am' => $rootOrganization->name_am,
                    'organization_type' => $rootOrganization->type ? [
                        'code' => $rootOrganization->type->code,
                        'name_en' => $rootOrganization->type->name_en,
                        'name_am' => $rootOrganization->type->name_am,
                    ] : null,
                    'status' => $rootOrganization->status instanceof \BackedEnum ? $rootOrganization->status->value : (string) $rootOrganization->status,
                    'logo_url' => $rootOrganization->logo_url,
                    'depth' => 0,
                    'child_count' => count($children),
                    'relationship_type' => null,
                    'effective_from' => null,
                    'effective_to' => null,
                    'can' => [
                        'edit' => false,
                        'remove' => false,
                        'addChild' => ($user?->can('organization-edges.create') ?? false)
                            && ($user?->can('hierarchy-versions.manageTree') ?? false)
                            && $version->status === HierarchyVersionStatus::Draft,
                    ],
                    'children' => $children,
                ];
            })
            ->filter(fn (array $node) => $node !== [])
            ->values()
            ->all();
    }

    public function summarizeVersionTree(array $tree): array
    {
        $summary = [
            'total_organizations' => 0,
            'total_relations' => 0,
            'root_nodes' => count($tree),
            'max_depth' => 0,
        ];

        $walk = function (array $nodes) use (&$walk, &$summary): void {
            foreach ($nodes as $node) {
                $summary['total_organizations']++;
                $summary['max_depth'] = max($summary['max_depth'], (int) ($node['depth'] ?? 0));

                if (($node['edge_id'] ?? null) !== null) {
                    $summary['total_relations']++;
                }

                $walk($node['children'] ?? []);
            }
        };

        $walk($tree);

        return $summary;
    }

    public function accessibleOrganizationIds(User $user): Collection
    {
        if ($this->hasUnrestrictedRole($user)) {
            return Organization::query()->pluck('id');
        }

        $publishedVersionId = $this->resolvePublishedVersionId() ?? 'none';
        $cacheKey = "org_scope.{$user->getKey()}.{$publishedVersionId}";

        if (isset($this->requestCache[$cacheKey])) {
            return $this->requestCache[$cacheKey];
        }

        $scopes = $user->organizationScopes()->active()->get();

        $ids = collect();

        foreach ($scopes as $scope) {
            if ($scope->scope_type === OrganizationScopeType::Citywide) {
                $result = Organization::query()->pluck('id');
                $this->requestCache[$cacheKey] = $result;

                return $result;
            }

            if ($scope->organization_id === null) {
                continue;
            }

            if ($scope->scope_type === OrganizationScopeType::Self) {
                $ids->push($scope->organization_id);

                continue;
            }

            if ($scope->scope_type === OrganizationScopeType::Subtree) {
                if ($publishedVersionId === 'none') {
                    Log::warning('OrganizationScopeService: no published hierarchy version found; subtree scope falls back to assigned organization only.', [
                        'user_id' => $user->getKey(),
                        'organization_id' => $scope->organization_id,
                    ]);
                    $ids->push($scope->organization_id);

                    continue;
                }

                $ids = $ids->merge(
                    OrganizationClosurePath::query()
                        ->where('hierarchy_version_id', $publishedVersionId)
                        ->where('ancestor_organization_id', $scope->organization_id)
                        ->pluck('descendant_organization_id')
                );
            }
        }

        $result = $ids->unique()->values();
        $this->requestCache[$cacheKey] = $result;

        return $result;
    }

    private function resolvePublishedVersionId(): ?string
    {
        return HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published->value)
            ->latest('approval_date')
            ->value('id');
    }
}
