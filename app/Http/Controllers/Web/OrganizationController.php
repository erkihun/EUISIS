<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Organizations\ArchiveOrganizationAction;
use App\Actions\Organizations\CreateOrganizationAction;
use App\Actions\Organizations\DeactivateOrganizationAction;
use App\Actions\Organizations\DeleteOrganizationAction;
use App\Actions\Organizations\GetOrganizationFullTreeAction;
use App\Actions\Organizations\UpdateOrganizationAction;
use App\Enums\AuditEventType;
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
use App\Support\MapConfig;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Feedback\Contracts\OrganizationFeedbackQrPermissions;

class OrganizationController extends Controller
{
    private const SORTABLE_COLUMNS = ['manual', 'code', 'name', 'type', 'category', 'parent', 'status', 'created_at'];

    /** Arranging needs every row on one page, so a move can cross what would be a page break. */
    private const ARRANGE_PAGE_SIZE = 1000;

    public function index(Request $request, OrganizationScopeService $scopeService, OrganizationDeletionGuard $organizationDeletionGuard): Response
    {
        $user = Auth::user();

        $this->authorize('viewAny', Organization::class);

        $publishedVersion = HierarchyVersion::query()
            ->where('status', 'published')
            ->latest('approval_date')
            ->first();

        // The saved order covers every organization, so it is arranged unfiltered.
        $arranging = $request->boolean('arrange') && ($user?->can('manageHierarchy', Organization::class) ?? false);

        $filters = $arranging
            ? ['search' => '', 'type' => '', 'status' => '', 'category' => '']
            : [
                'search' => $request->string('search')->trim()->toString(),
                'type' => $request->string('type')->toString(),
                'status' => $request->string('status')->toString(),
                'category' => $request->string('category')->toString(),
            ];

        // Archived institutions stay out of the working list and open on request, as history.
        $organizationsQuery = ($filters['status'] === OrganizationStatus::Archived->value ? Organization::withTrashed() : Organization::query()->where('status', '!=', OrganizationStatus::Archived->value))
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
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']));

        $hasManualOrder = Organization::query()->whereNotNull('sort_order')->exists();
        $sort = $request->string('sort')->toString();
        $sort = match (true) {
            $arranging => 'manual',
            in_array($sort, self::SORTABLE_COLUMNS, true) => $sort,
            $hasManualOrder => 'manual',
            default => 'name',
        };
        $direction = $sort !== 'manual' && $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';
        $this->applyListSort($organizationsQuery, $sort, $direction, $publishedVersion?->id);

        $scopeService->applyOrganizationScope($organizationsQuery, $user, 'id');

        $paginator = $organizationsQuery
            ->paginate($arranging ? self::ARRANGE_PAGE_SIZE : 20, ['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id', 'created_at'])
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
            'sort' => ['column' => $sort, 'direction' => $direction],
            'arranging' => $arranging,
            'hasManualOrder' => $hasManualOrder,
            'filterOptions' => [
                'types' => OrganizationType::query()->orderBy('name_en')->get(['code', 'name_en', 'name_am']),
                'statuses' => collect(OrganizationStatus::cases())->pluck('value'),
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

    private function isArchived(Organization $organization): bool
    {
        return $organization->trashed() || $organization->status === OrganizationStatus::Archived;
    }

    /**
     * Organizational memory: every hierarchy version this institution appeared in,
     * who it sat under then and how many sat under it, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function versionHistory(Organization $organization): array
    {
        $edges = OrganizationEdge::query()
            ->where(fn ($query) => $query->where('child_organization_id', $organization->id)->orWhere('parent_organization_id', $organization->id))
            ->get(['hierarchy_version_id', 'parent_organization_id', 'child_organization_id']);
        $parents = Organization::withTrashed()->whereIn('id', $edges->pluck('parent_organization_id')->unique())->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');

        return HierarchyVersion::query()->whereIn('id', $edges->pluck('hierarchy_version_id')->unique())
            ->orderByDesc('effective_from')->orderByDesc('created_at')
            ->get(['id', 'version_name', 'status', 'effective_from', 'effective_to'])
            ->map(function (HierarchyVersion $version) use ($edges, $parents, $organization): array {
                $inVersion = $edges->where('hierarchy_version_id', $version->id);
                $above = $inVersion->firstWhere('child_organization_id', $organization->id);

                return [
                    'id' => $version->id,
                    'version_name' => $version->version_name,
                    'status' => $version->status->value,
                    'effective_from' => $version->effective_from?->toDateString(),
                    'effective_to' => $version->effective_to?->toDateString(),
                    'parent' => $above ? $parents->get($above->parent_organization_id)?->only(['code', 'name_en', 'name_am']) : null,
                    'children_count' => $inVersion->where('parent_organization_id', $organization->id)->count(),
                ];
            })->values()->all();
    }

    /**
     * Whitelisted, never passed through: these names choose what reaches ORDER BY.
     * Names follow the reader's language, falling back to English where Amharic is
     * blank, and the code breaks ties so paging stays stable.
     *
     * @param  Builder<Organization>  $query
     */
    private function applyListSort(Builder $query, string $sort, string $direction, ?string $publishedVersionId): void
    {
        $name = app()->getLocale() === 'am' ? 'COALESCE(%1$s.name_am, %1$s.name_en)' : 'COALESCE(%1$s.name_en, %1$s.name_am)';
        $type = fn (string $column) => DB::table('organization_types')
            ->selectRaw($column)
            ->whereColumn('organization_types.id', 'organizations.organization_type_id')
            ->limit(1);

        // Databases disagree on where NULL sorts; a row with nothing to compare stays at the end either way.
        $blanksLast = fn ($value) => $query
            ->orderByRaw('('.$value->toSql().') IS NULL', $value->getBindings())
            ->orderBy($value, $direction);

        match ($sort) {
            'manual' => $query->orderByRaw('sort_order IS NULL')->orderBy('sort_order'),
            'code', 'status', 'created_at' => $query->orderBy($sort, $direction),
            'name' => $query->orderByRaw(sprintf($name, 'organizations').' '.$direction),
            'type' => $query->orderBy($type(sprintf($name, 'organization_types')), $direction),
            'category' => $blanksLast($type('category')),
            'parent' => $blanksLast(
                DB::table('organization_edges')
                    ->join('organizations as parents', 'parents.id', '=', 'organization_edges.parent_organization_id')
                    ->selectRaw(sprintf($name, 'parents'))
                    ->whereColumn('organization_edges.child_organization_id', 'organizations.id')
                    ->where('organization_edges.hierarchy_version_id', $publishedVersionId)
                    ->limit(1),
            ),
        };

        $query->orderBy('code');
    }

    /**
     * Saves the order chosen on the list. It only decides how the list is shown, so
     * it writes past the model: a new order is not a change to the organization and
     * must not touch updated_at or anything listening for organization updates.
     */
    public function reorder(Request $request, OrganizationScopeService $scopeService, WriteAuditLogAction $audit): RedirectResponse
    {
        $this->authorize('manageHierarchy', Organization::class);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::ARRANGE_PAGE_SIZE],
            'ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('organizations', 'id')
                    ->whereNull('deleted_at')
                    ->whereNot('status', OrganizationStatus::Archived->value),
            ],
        ]);
        $ids = array_values($validated['ids']);

        $query = Organization::query()->whereIn('id', $ids);
        $scopeService->applyOrganizationScope($query, $request->user(), 'id');
        $organizations = $query->get(['id', 'sort_order'])->keyBy('id');
        abort_unless($organizations->count() === count($ids), 403);

        DB::transaction(function () use ($ids, $organizations, $request, $audit): void {
            foreach ($ids as $index => $id) {
                $organization = $organizations->get($id);
                $old = $organization->sort_order;
                $new = $index + 1;
                if ($old === $new) {
                    continue;
                }

                DB::table('organizations')->where('id', $id)->update(['sort_order' => $new]);
                $audit->execute(
                    AuditEventType::OrganizationUpdated,
                    $request->user(),
                    $organization,
                    $id,
                    oldValues: ['sort_order' => $old],
                    newValues: ['sort_order' => $new],
                );
            }
        });

        return to_route('organizations.index')
            ->with('flash', ['message' => __('organizations.order_saved'), 'type' => 'success']);
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
        if ($user === null || $organization === null || $this->isArchived($organization)) {
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
            'map' => MapConfig::forPicker(),
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
            'versionHistory' => $this->versionHistory($organization),
            'isArchived' => $this->isArchived($organization),
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
            // An archived institution is read-only history: no action is offered, and its other routes do not resolve it.
            'can' => $this->isArchived($organization)
                ? ['update' => false, 'delete' => false, 'archive' => false, 'deactivate' => false, 'createChild' => false, 'feedbackQr' => false]
                : [
                    'update' => $user?->can('update', $organization) ?? false,
                    'delete' => $user?->can('organizations.delete')
                        && ($user !== null && $organizationScopeService->canManageWithinScope($user, $organization)),
                    'archive' => $user?->can('archive', $organization) ?? false,
                    'deactivate' => $user?->can('deactivate', $organization) ?? false,
                    'createChild' => $user?->can('createChild', $organization) ?? false,
                    'feedbackQr' => $user !== null && app(OrganizationFeedbackQrPermissions::class)->can($user, 'view', $organization),
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
            // The link goes into a draft hierarchy version, and the parent's page
            // shows the published one, so say when the child will appear there.
            $versionName = HierarchyVersion::query()->whereKey($request->validated('hierarchy_version_id'))->value('version_name');

            return to_route('organizations.show', $parentOrganizationId)
                ->with('flash', ['message' => __('organizations.child_organization_pending_publish', ['version' => (string) $versionName]), 'type' => 'success']);
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
            'map' => MapConfig::forPicker(),
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
