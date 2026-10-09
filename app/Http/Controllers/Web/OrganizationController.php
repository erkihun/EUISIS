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
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\OrganizationRelationships\ReportingLineService;
use App\Services\Organizations\OrganizationDeletionGuard;
use App\Services\Organizations\OrganizationStatisticsService;
use App\Services\Organizations\ParentOrganizationOptionsService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scopeService, OrganizationDeletionGuard $organizationDeletionGuard): Response
    {
        $user = Auth::user();

        $this->authorize('viewAny', Organization::class);

        $publishedVersion = HierarchyVersion::query()
            ->where('status', 'published')
            ->latest('approval_date')
            ->first();

        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'type' => $request->string('type')->toString(),
            'status' => $request->string('status')->toString(),
            'category' => $request->string('category')->toString(),
        ];

        $organizationsQuery = Organization::query()
            ->where('status', '!=', OrganizationStatus::Archived->value)
            ->with('type:id,code,name_en,name_am,category')
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $query->where(fn ($inner) => $inner
                    ->where('code', ci_like_operator(), $search)
                    ->orWhere('name_en', ci_like_operator(), $search)
                    ->orWhere('name_am', ci_like_operator(), $search));
            })
            ->when($filters['type'] !== '', fn ($query) => $query->whereHas('type', fn ($typeQuery) => $typeQuery->where('code', $filters['type'])))
            ->when($filters['category'] !== '', fn ($query) => $query->whereHas('type', fn ($typeQuery) => $typeQuery->where('category', $filters['category'])))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->orderBy('name_en');

        $scopeService->applyOrganizationScope($organizationsQuery, $user, 'id');

        $paginator = $organizationsQuery
            ->paginate(20, ['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id', 'created_at'])
            ->withQueryString();
        $pageOrganizations = $paginator->getCollection();
        $deletionBlockers = $organizationDeletionGuard->reasonsFor($pageOrganizations);

        $parentIdsByChild = $publishedVersion === null
            ? collect()
            : OrganizationEdge::query()
                ->where('hierarchy_version_id', $publishedVersion->id)
                ->whereIn('child_organization_id', $pageOrganizations->pluck('id'))
                ->pluck('parent_organization_id', 'child_organization_id');
        $parents = Organization::query()
            ->whereIn('id', $parentIdsByChild->values()->unique())
            ->get(['id', 'code', 'name_en', 'name_am'])
            ->keyBy('id');

        $organizationRows = $pageOrganizations
            ->map(function (Organization $organization) use ($user, $deletionBlockers, $parentIdsByChild, $parents): array {
                $parent = $parents->get($parentIdsByChild->get($organization->id));

                return [
                    'id' => $organization->id,
                    'code' => $organization->code,
                    'name_en' => $organization->name_en,
                    'name_am' => $organization->name_am,
                    'status' => $organization->status,
                    'type' => $organization->type,
                    'created_at' => $organization->created_at?->toIso8601String(),
                    'parent' => $parent?->only(['id', 'code', 'name_en', 'name_am']),
                    'can' => $this->rowActionPermissions($user, $organization),
                    'deletion_blockers' => $deletionBlockers[$organization->id] ?? [],
                ];
            })->values();

        $paginator->setCollection($organizationRows);

        return Inertia::render('Organizations/Index', [
            'organizations' => $paginator,
            'filters' => $filters,
            'filterOptions' => [
                'types' => OrganizationType::query()->orderBy('name_en')->get(['code', 'name_en', 'name_am']),
                'statuses' => collect(OrganizationStatus::cases())->reject(fn (OrganizationStatus $status) => $status === OrganizationStatus::Archived)->pluck('value'),
                'categories' => OrganizationType::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            ],
            'stats' => $this->indexStats($user, $scopeService),
            'publishedVersion' => $publishedVersion?->only(['id', 'version_name', 'approval_date']),
            'hierarchyVersions' => HierarchyVersion::query()->orderByDesc('created_at')->get(['id', 'version_name', 'status', 'approval_date']),
            'can' => [
                'create' => $user?->can('create', Organization::class) ?? false,
                'manageHierarchy' => $user?->can('manageHierarchy', Organization::class) ?? false,
            ],
        ]);
    }

    /**
     * KPI summary counts for the index header, scoped to the organizations the
     * user may access. Cheap COUNT queries — passed as page props, never as
     * global Inertia shared props.
     *
     * @return array{total: int, active: int, inactive: int, types: int}
     */
    private function indexStats(User $user, OrganizationScopeService $scopeService): array
    {
        $scoped = function () use ($user, $scopeService) {
            $query = Organization::query()->where('status', '!=', OrganizationStatus::Archived->value);
            $scopeService->applyOrganizationScope($query, $user, 'id');

            return $query;
        };

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
            'delete' => $user->can('organizations.delete')
                && app(OrganizationScopeService::class)->canManageWithinScope($user, $organization),
            'archive' => $user->can('archive', $organization),
            'deactivate' => $user->can('deactivate', $organization),
            'createChild' => $user->can('createChild', $organization),
        ];
    }

    public function create(
        Request $request,
        ParentOrganizationOptionsService $parentOrganizationOptionsService,
        OrganizationScopeService $organizationScopeService,
    ): Response {
        $this->authorize('create', Organization::class);

        $selectedParentId = $request->string('parent')->toString() ?: null;
        $parentOptions = $parentOrganizationOptionsService->resolve(
            $request->user(),
            selectedId: $selectedParentId,
        );
        $requiresParentOrganization = $organizationScopeService->mustCreateUnderAssignedOrganization($request->user());

        if ($requiresParentOrganization && count($parentOptions['options']) === 1) {
            $parentOptions['selected'] = $parentOptions['options'][0];
        }

        return Inertia::render('Organizations/Create', [
            'organizationTypes' => OrganizationType::query()->active()->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code']),
            'hierarchyVersions' => HierarchyVersion::query()
                ->where('status', HierarchyVersionStatus::Draft->value)
                ->orderByDesc('created_at')
                ->get(['id', 'version_name', 'status']),
            'parentOrganizationOptions' => $parentOptions['options'],
            'selectedParentOrganization' => $parentOptions['selected'],
            'requiresParentOrganization' => $requiresParentOrganization,
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
        OrganizationStatisticsService $organizationStatisticsService,
    ): Response {
        $user = Auth::user();

        $this->authorize('view', $organization);

        // Fail closed: a scoped actor whose accessible set resolves empty must
        // not fall through to unrestricted access.
        if (! $organizationScopeService->canAccess($user, $organization->id)) {
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
            'statistics' => $organizationStatisticsService->forOrganization($organization),
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
                'delete' => $user?->can('organizations.delete')
                    && ($user !== null && $organizationScopeService->canManageWithinScope($user, $organization)),
                'archive' => $user?->can('archive', $organization) ?? false,
                'deactivate' => $user?->can('deactivate', $organization) ?? false,
                'createChild' => $user?->can('createChild', $organization) ?? false,
            ],
            'deletionBlockers' => $organizationDeletionGuard->reasons($organization),
        ]);
    }

    /**
     * Organogram for a single organization: Organization -> Units -> Positions
     * -> assigned employee. Reuses the same scoped tree builder as the detail
     * page, so there is exactly one definition of the structure.
     *
     * `?format=pdf` renders the printable variant through the existing dompdf
     * integration; everything else returns the interactive Inertia page.
     */
    public function organogram(
        Request $request,
        Organization $organization,
        OrganizationScopeService $organizationScopeService,
        GetOrganizationFullTreeAction $getOrganizationFullTreeAction,
    ): Response|HttpResponse {
        $user = Auth::user();

        $this->authorize('view', $organization);

        // Fail closed — never fall through to unrestricted access.
        if (! $organizationScopeService->canAccess($user, $organization->id)) {
            abort(403);
        }

        $organization->load('type:id,code,name_en,name_am');
        $tree = $getOrganizationFullTreeAction->execute($organization);

        if ($request->string('format')->toString() === 'pdf') {
            return Pdf::loadView('organizations.organogram', [
                'tree' => $tree,
                'locale' => app()->getLocale(),
                'generatedAt' => now(),
            ])
                ->setPaper('a3', 'landscape')
                ->download(sprintf('organogram-%s.pdf', $organization->code));
        }

        return Inertia::render('Organizations/Organogram', [
            'tree' => $tree,
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
            'organizationTypes' => OrganizationType::query()
                ->where(fn ($query) => $query->active()->orWhere('id', $organization->organization_type_id))
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am', 'code']),
        ]);
    }

    public function update(
        OrganizationUpdateRequest $request,
        Organization $organization,
        UpdateOrganizationAction $updateOrganizationAction,
    ): RedirectResponse {
        $updateOrganizationAction->execute($request->validated(), $organization, $request->user());

        return to_route('organizations.show', $organization)
            ->with('flash', ['message' => __('organizations.updated_successfully'), 'type' => 'success']);
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
