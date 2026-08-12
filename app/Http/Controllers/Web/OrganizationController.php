<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Organizations\ArchiveOrganizationAction;
use App\Actions\Organizations\CreateOrganizationAction;
use App\Actions\Organizations\DeactivateOrganizationAction;
use App\Actions\Organizations\DeleteOrganizationAction;
use App\Actions\Organizations\GetOrganizationFullTreeAction;
use App\Actions\Organizations\UpdateOrganizationAction;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationStoreRequest;
use App\Http\Requests\OrganizationUpdateRequest;
use App\Http\Resources\ReportingLineResource;
use App\Models\HierarchyVersion;
use App\Models\InstitutionOffice;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\OrganizationRelationships\ReportingLineService;
use App\Services\Organizations\OrganizationDeletionGuard;
use App\Services\Organizations\ParentOrganizationOptionsService;
use App\Services\OrganizationScope\OrganizationScopeService;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(OrganizationScopeService $scopeService, OrganizationDeletionGuard $organizationDeletionGuard): Response
    {
        $user = Auth::user();

        $publishedVersion = HierarchyVersion::query()
            ->where('status', 'published')
            ->latest('approval_date')
            ->first();

        $allowedOrgIds = $user !== null ? $scopeService->accessibleOrganizationIds($user) : collect();

        $tree = $scopeService->buildFlatTreeForIndex($publishedVersion, $allowedOrgIds->isNotEmpty() ? $allowedOrgIds->all() : null);

        if ($user !== null) {
            $organizationsById = Organization::query()
                ->whereIn('id', collect($tree)->pluck('id'))
                ->with('type:id,code,name_en,name_am,category')
                ->get()
                ->keyBy('id');

            $tree = collect($tree)
                ->map(function (array $node) use ($organizationsById, $user, $organizationDeletionGuard): array {
                    $organization = $organizationsById->get($node['id']);

                    $node['can'] = $this->rowActionPermissions($user, $organization);
                    $node['deletion_blockers'] = $organization !== null
                        ? $organizationDeletionGuard->reasons($organization)
                        : [];
                    $node['created_at'] = $organization?->created_at?->toIso8601String();

                    if (is_array($node['type']) && $organization?->type !== null) {
                        $node['type']['category'] = $organization->type->category;
                    }

                    return $node;
                })
                ->all();
        }

        $assignedIds = collect($tree)->pluck('id');

        $unassignedQuery = Organization::query()
            ->where('status', '!=', OrganizationStatus::Archived->value)
            ->when($assignedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $assignedIds))
            ->with('type:id,code,name_en,name_am,category')
            ->orderBy('name_en');

        if ($allowedOrgIds->isNotEmpty()) {
            $unassignedQuery->whereIn('id', $allowedOrgIds);
        }

        $unassigned = $unassignedQuery->get(['id', 'code', 'name_en', 'name_am', 'status', 'effective_from', 'effective_to', 'organization_type_id', 'created_at'])
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'status' => $organization->status,
                'type' => $organization->type,
                'created_at' => $organization->created_at?->toIso8601String(),
                'can' => $this->rowActionPermissions($user, $organization),
                'deletion_blockers' => $organizationDeletionGuard->reasons($organization),
            ])
            ->values();

        $canManage = $user?->can('organizations.manage') ?? false;

        return Inertia::render('Organizations/Index', [
            'tree' => $tree,
            'unassigned' => $unassigned,
            'stats' => $this->indexStats($allowedOrgIds),
            'publishedVersion' => $publishedVersion?->only(['id', 'version_name', 'approval_date']),
            'hierarchyVersions' => HierarchyVersion::query()->orderByDesc('created_at')->get(['id', 'version_name', 'status', 'approval_date']),
            'can' => [
                'create' => $canManage,
                'manageHierarchy' => $canManage,
            ],
        ]);
    }

    /**
     * KPI summary counts for the index header, scoped to the organizations the
     * user may access. Cheap COUNT queries — passed as page props, never as
     * global Inertia shared props.
     *
     * @param  Collection<int, string>  $allowedOrgIds
     * @return array{total: int, active: int, inactive: int, types: int}
     */
    private function indexStats(Collection $allowedOrgIds): array
    {
        $scoped = fn () => Organization::query()
            ->where('status', '!=', OrganizationStatus::Archived->value)
            ->when($allowedOrgIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $allowedOrgIds->all()));

        return [
            'total' => $scoped()->count(),
            'active' => $scoped()->where('status', OrganizationStatus::Active->value)->count(),
            'inactive' => $scoped()->where('status', OrganizationStatus::Inactive->value)->count(),
            'types' => $scoped()->distinct()->count('organization_type_id'),
        ];
    }

    /**
     * Row-level action permissions shared by the hierarchy tree and the
     * unassigned-organizations list. `delete` is the raw permission check
     * (not the full `delete` ability, which also folds in the dependency
     * guard) so the UI can distinguish "hidden — no permission" from
     * "visible but disabled — has dependencies" (see `deletion_blockers`).
     *
     * @return array{update: bool, delete: bool, archive: bool, deactivate: bool, createChild: bool}
     */
    private function rowActionPermissions(?User $user, ?Organization $organization): array
    {
        if ($user === null || $organization === null) {
            return ['update' => false, 'delete' => false, 'archive' => false, 'deactivate' => false, 'createChild' => false];
        }

        return [
            'update' => $user->can('update', $organization),
            'delete' => $user->can('organizations.manage'),
            'archive' => $user->can('archive', $organization),
            'deactivate' => $user->can('deactivate', $organization),
            'createChild' => $user->can('createChild', $organization),
        ];
    }

    public function create(Request $request, ParentOrganizationOptionsService $parentOrganizationOptionsService): Response
    {
        $this->authorize('create', Organization::class);

        $selectedParentId = $request->string('parent')->toString() ?: null;
        $parentOptions = $parentOrganizationOptionsService->resolve(
            $request->user(),
            selectedId: $selectedParentId,
        );

        return Inertia::render('Organizations/Create', [
            'organizationTypes' => OrganizationType::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code']),
            'hierarchyVersions' => HierarchyVersion::query()
                ->where('status', HierarchyVersionStatus::Draft->value)
                ->orderByDesc('created_at')
                ->get(['id', 'version_name', 'status']),
            'parentOrganizationOptions' => $parentOptions['options'],
            'selectedParentOrganization' => $parentOptions['selected'],
        ]);
    }

    public function parentOptions(
        Request $request,
        ParentOrganizationOptionsService $parentOrganizationOptionsService,
    ): JsonResponse {
        $this->authorize('create', Organization::class);

        $resolved = $parentOrganizationOptionsService->resolve(
            $request->user(),
            search: $request->string('q')->toString() ?: null,
            selectedId: $request->string('selected_id')->toString() ?: null,
            hierarchyVersionId: $request->string('hierarchy_version_id')->toString() ?: null,
            currentOrganizationId: $request->string('current_organization_id')->toString() ?: null,
        );

        return response()->json($resolved);
    }

    public function show(
        Organization $organization,
        OrganizationScopeService $organizationScopeService,
        ReportingLineService $reportingLineService,
        OrganizationDeletionGuard $organizationDeletionGuard,
        GetOrganizationFullTreeAction $getOrganizationFullTreeAction,
    ): Response {
        $user = Auth::user();

        $this->authorize('view', $organization);

        $accessibleOrganizationIds = $organizationScopeService->accessibleOrganizationIds($user);
        if ($accessibleOrganizationIds->isNotEmpty() && ! $accessibleOrganizationIds->contains($organization->id)) {
            abort(403);
        }

        $latestVersionId = HierarchyVersion::query()
            ->where('status', 'published')
            ->latest('approval_date')
            ->value('id');

        $organization->load(['type', 'mergedInto', 'nameHistories']);

        $parentOrganizationId = $latestVersionId !== null
            ? $organization->parentEdges()
                ->where('hierarchy_version_id', $latestVersionId)
                ->value('parent_organization_id')
            : null;

        $parentOrganization = $parentOrganizationId !== null
            ? Organization::query()->find($parentOrganizationId, ['id', 'code', 'name_en', 'name_am'])
            : null;

        $institutionOffices = InstitutionOffice::query()
            ->where('institution_id', $organization->id)
            ->orderBy('name_en')
            ->get(['id', 'office_code', 'name_en', 'office_level', 'status'])
            ->toArray();

        $descendants = $latestVersionId === null
            ? []
            : $organizationScopeService->descendantsForOrganization($organization->id, $latestVersionId);
        $descendants = $this->resolveDescendantNames($descendants);

        return Inertia::render('Organizations/Show', [
            'organization' => $this->organizationPayload($organization),
            'parentOrganizationId' => $parentOrganizationId,
            'parentOrganization' => $parentOrganization?->only(['id', 'code', 'name_en', 'name_am']),
            'currentAssignmentsCount' => $organization->assignments()->where('is_current', true)->count(),
            'structureSummary' => [
                'units' => $organization->organizationUnits()->count(),
                'positions' => $organization->positions()->count(),
                'descendants' => count($descendants),
            ],
            'structureTree' => $getOrganizationFullTreeAction->execute($organization),
            'descendants' => $descendants,
            'institutionOffices' => $institutionOffices,
            'reportingOffices' => ReportingLineResource::collection(
                $reportingLineService->getOfficesReportingToOrganization($organization),
            )->resolve(request()),
            'reportingUnits' => ReportingLineResource::collection(
                $reportingLineService->getUnitsReportingToOrganization($organization),
            )->resolve(request()),
            // `delete` is deliberately the raw permission check (not the full
            // `delete` ability, which also folds in the dependency guard) so the
            // UI can distinguish "hidden — no permission" from "visible but
            // disabled — has dependencies". `deletionBlockers` carries the latter.
            'can' => [
                'update' => $user?->can('update', $organization) ?? false,
                'delete' => $user?->can('organizations.manage') ?? false,
                'archive' => $user?->can('archive', $organization) ?? false,
                'deactivate' => $user?->can('deactivate', $organization) ?? false,
                'createChild' => $user?->can('createChild', $organization) ?? false,
            ],
            'deletionBlockers' => $organizationDeletionGuard->reasons($organization),
        ]);
    }

    public function store(OrganizationStoreRequest $request, CreateOrganizationAction $createOrganizationAction): RedirectResponse
    {
        $parentOrganizationId = $request->validated('parent_organization_id');
        $organization = $createOrganizationAction->execute($request->validated(), $request->user());

        if ($parentOrganizationId !== null) {
            return to_route('organizations.show', $parentOrganizationId)
                ->with('flash', ['message' => __('organizations.child_organization_created_successfully'), 'type' => 'success']);
        }

        return to_route('organizations.show', $organization)
            ->with('flash', ['message' => __('organizations.created_successfully'), 'type' => 'success']);
    }

    public function edit(Organization $organization): Response
    {
        $this->authorize('update', $organization);

        return Inertia::render('Organizations/Edit', [
            'organization' => $this->organizationPayload($organization->load('type')),
            'organizationTypes' => OrganizationType::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code']),
        ]);
    }

    public function update(
        OrganizationUpdateRequest $request,
        Organization $organization,
        UpdateOrganizationAction $updateOrganizationAction,
    ): RedirectResponse {
        $updateOrganizationAction->execute($request->validated(), $organization, $request->user());

        return to_route('organizations.show', $organization);
    }

    /**
     * The safe, non-destructive alternative to destroy(): marks the
     * organization archived without removing it or any of its references.
     */
    public function archive(
        Request $request,
        Organization $organization,
        ArchiveOrganizationAction $archiveOrganizationAction,
    ): RedirectResponse {
        $this->authorize('archive', $organization);

        $archiveOrganizationAction->execute($organization, $request->user());

        return to_route('organizations.index')
            ->with('flash', ['message' => __('organizations.archived_successfully'), 'type' => 'success']);
    }

    /**
     * The lighter-weight safe alternative to destroy(): marks the
     * organization inactive without removing it or any of its references.
     */
    public function deactivate(
        Request $request,
        Organization $organization,
        DeactivateOrganizationAction $deactivateOrganizationAction,
    ): RedirectResponse {
        $this->authorize('deactivate', $organization);

        $deactivateOrganizationAction->execute($organization, $request->user());

        return to_route('organizations.show', $organization)
            ->with('flash', ['message' => __('organizations.deactivated_successfully'), 'type' => 'success']);
    }

    /**
     * Physically (soft-)delete the organization. Blocked whenever it is still
     * used — see OrganizationDeletionGuard — in which case the user is
     * redirected back with a localized error pointing at Deactivate/Archive.
     */
    public function destroy(
        Request $request,
        Organization $organization,
        DeleteOrganizationAction $deleteOrganizationAction,
    ): RedirectResponse {
        $this->authorize('delete', $organization);

        $deleteOrganizationAction->execute($organization, $request->user());

        return to_route('organizations.index')
            ->with('flash', ['message' => __('organizations.deleted_successfully'), 'type' => 'success']);
    }

    /**
     * Enrich raw descendant rows (id + depth) with the organization's code and
     * name so the Show page can render readable rows instead of raw UUIDs.
     *
     * @param  iterable<int, mixed>  $descendants
     * @return list<array{descendant_organization_id: string, depth: int, code: string|null, name_en: string|null, name_am: string|null}>
     */
    private function resolveDescendantNames(iterable $descendants): array
    {
        $rows = collect($descendants)->map(fn ($row): array => (array) (is_array($row) ? $row : $row->toArray()))->all();

        $ids = collect($rows)->pluck('descendant_organization_id')->filter()->all();

        $orgs = $ids === []
            ? collect()
            : Organization::query()->whereIn('id', $ids)->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');

        return collect($rows)->map(function (array $row) use ($orgs): array {
            $org = $orgs->get($row['descendant_organization_id'] ?? null);

            return [
                'descendant_organization_id' => (string) ($row['descendant_organization_id'] ?? ''),
                'depth' => (int) ($row['depth'] ?? 0),
                'code' => $org?->code,
                'name_en' => $org?->name_en,
                'name_am' => $org?->name_am,
            ];
        })->all();
    }

    private function organizationPayload(Organization $organization): array
    {
        $payload = $organization->toArray();
        $payload['effective_from'] = $this->dateString($organization->effective_from);
        $payload['effective_to'] = $this->dateString($organization->effective_to);

        if ($organization->relationLoaded('nameHistories')) {
            $payload['name_histories'] = $organization->nameHistories
                ->map(function ($history): array {
                    $historyPayload = $history->toArray();
                    $historyPayload['effective_from'] = $this->dateString($history->effective_from);
                    $historyPayload['effective_to'] = $this->dateString($history->effective_to);

                    return $historyPayload;
                })
                ->values()
                ->all();
        }

        return $payload;
    }

    private function dateString(mixed $date): ?string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return is_string($date) && $date !== '' ? substr($date, 0, 10) : null;
    }
}
