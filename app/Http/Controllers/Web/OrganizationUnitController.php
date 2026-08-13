<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\OrganizationUnits\ArchiveOrganizationUnitAction;
use App\Actions\OrganizationUnits\CopyOrganizationUnitStructureAction;
use App\Actions\OrganizationUnits\CreateOrganizationUnitAction;
use App\Actions\OrganizationUnits\RestoreOrganizationUnitAction;
use App\Actions\OrganizationUnits\UpdateOrganizationUnitAction;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationUnitStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationUnitRequest;
use App\Http\Requests\StoreOrganizationUnitStructureCopyRequest;
use App\Http\Requests\UpdateOrganizationUnitRequest;
use App\Http\Resources\OrganizationUnitRelationshipResource;
use App\Http\Resources\OrganizationUnitResource;
use App\Models\HierarchyVersion;
use App\Models\InstitutionOffice;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType as OrganizationUnitTypeModel;
use App\Services\CodeGeneration\PositionCodeContextResolver;
use App\Services\Hierarchy\HierarchyVersionResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\OrganizationUnits\OrganizationUnitTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationUnitController extends Controller
{
    public function index(Request $request, OrganizationUnitTreeService $treeService, HierarchyVersionResolver $resolver): Response
    {
        $this->authorize('viewAny', OrganizationUnit::class);

        $user = Auth::user();
        $accessibleOrgIds = $user?->accessibleOrganizationIds() ?? [];

        $orgQuery = Organization::query()
            ->with(['type:id,name_en,name_am,code'])
            ->withCount(['organizationUnits' => fn ($q) => $q->whereNull('deleted_at')])
            ->orderBy('name_en');

        if (! empty($accessibleOrgIds)) {
            $orgQuery->whereIn('id', $accessibleOrgIds);
        }

        $organizations = $orgQuery->get([
            'id', 'organization_type_id', 'code', 'name_en', 'name_am',
            'status', 'logo_path', 'effective_from',
        ]);

        // Resolve the best available hierarchy version (published → draft → null)
        $resolvedVersion = $resolver->resolveForRequest($request);

        $hasPublishedHierarchy = $resolvedVersion !== null
            && $resolvedVersion->status === HierarchyVersionStatus::Published;

        $usingDraftFallback = $resolvedVersion !== null
            && $resolvedVersion->status === HierarchyVersionStatus::Draft;

        $usingFlatFallback = $resolvedVersion === null;

        // Build available versions list for the selector dropdown
        $availableVersions = HierarchyVersion::query()
            ->whereIn('status', [HierarchyVersionStatus::Published->value, HierarchyVersionStatus::Draft->value])
            ->latest('updated_at')
            ->get(['id', 'version_name', 'status', 'effective_from'])
            ->map(fn (HierarchyVersion $v) => [
                'id' => $v->id,
                'name' => $v->version_name,
                'status' => $v->status->value,
                'is_draft' => $v->status === HierarchyVersionStatus::Draft,
                'effective_from' => $v->effective_from?->toDateString(),
            ])
            ->values()
            ->all();

        $selectedVersion = $resolvedVersion !== null ? [
            'id' => $resolvedVersion->id,
            'name' => $resolvedVersion->version_name,
            'status' => $resolvedVersion->status->value,
            'is_draft' => $resolvedVersion->status === HierarchyVersionStatus::Draft,
            'effective_from' => $resolvedVersion->effective_from?->toDateString(),
        ] : null;

        $orgMap = $organizations->keyBy('id');

        if ($resolvedVersion !== null) {
            $edges = OrganizationEdge::query()
                ->where('hierarchy_version_id', $resolvedVersion->id)
                ->get(['parent_organization_id', 'child_organization_id']);

            $childrenMap = [];
            $edgeChildIds = [];
            foreach ($edges as $edge) {
                $pid = $edge->parent_organization_id;
                $cid = $edge->child_organization_id;
                // Only include edges where both orgs are in accessible set
                if ($orgMap->has($pid) && $orgMap->has($cid)) {
                    $childrenMap[$pid][] = $cid;
                    $edgeChildIds[] = $cid;
                }
            }
            $edgeChildIds = array_unique($edgeChildIds);

            $buildOrgNode = function (string $orgId, int $depth) use (&$buildOrgNode, $orgMap, $childrenMap): ?array {
                $org = $orgMap->get($orgId);
                if (! $org) {
                    return null;
                }
                $children = [];
                foreach ($childrenMap[$orgId] ?? [] as $childId) {
                    $child = $buildOrgNode($childId, $depth + 1);
                    if ($child !== null) {
                        $children[] = $child;
                    }
                }

                return [
                    'id' => $org->id,
                    'code' => $org->code,
                    'name_en' => $org->name_en,
                    'name_am' => $org->name_am,
                    'status' => $org->status,
                    'logo_url' => $org->logo_url,
                    'has_logo' => $org->has_logo,
                    'organization_units_count' => $org->organization_units_count,
                    'type' => $org->type ? [
                        'id' => $org->type->id,
                        'code' => $org->type->code,
                        'name_en' => $org->type->name_en,
                        'name_am' => $org->type->name_am,
                    ] : null,
                    'depth' => $depth,
                    'children' => $children,
                ];
            };

            $organizationTree = $organizations
                ->filter(fn ($org) => ! in_array($org->id, $edgeChildIds, true))
                ->map(fn ($org) => $buildOrgNode($org->id, 0))
                ->filter()
                ->values()
                ->all();
        } else {
            // Fallback: flat list, each org as depth-0 node with no children
            $organizationTree = $organizations->map(fn ($org) => [
                'id' => $org->id,
                'code' => $org->code,
                'name_en' => $org->name_en,
                'name_am' => $org->name_am,
                'status' => $org->status,
                'logo_url' => $org->logo_url,
                'has_logo' => $org->has_logo,
                'organization_units_count' => $org->organization_units_count,
                'type' => $org->type ? [
                    'id' => $org->type->id,
                    'code' => $org->type->code,
                    'name_en' => $org->type->name_en,
                    'name_am' => $org->type->name_am,
                ] : null,
                'depth' => 0,
                'children' => [],
            ])->values()->all();
        }

        $selectedOrganization = null;
        $organizationUnits = [];

        if ($request->filled('organization_id')) {
            $orgId = $request->get('organization_id');

            if (! empty($accessibleOrgIds) && ! in_array($orgId, $accessibleOrgIds, true)) {
                abort(403);
            }

            $selectedOrganization = Organization::query()
                ->with(['type:id,name_en,name_am,code'])
                ->withCount(['organizationUnits' => fn ($q) => $q->whereNull('deleted_at')])
                ->find($orgId, [
                    'id', 'organization_type_id', 'code', 'name_en', 'name_am',
                    'status', 'logo_path', 'effective_from',
                ]);

            if ($selectedOrganization !== null) {
                $organizationUnits = $treeService->buildTreeWithMeta($selectedOrganization->id, $user);
            }
        }

        return Inertia::render('OrganizationUnits/Index', [
            'organizationTree' => $organizationTree,
            'hasPublishedHierarchy' => $hasPublishedHierarchy,
            'usingDraftFallback' => $usingDraftFallback,
            'usingFlatFallback' => $usingFlatFallback,
            'selectedVersion' => $selectedVersion,
            'availableVersions' => $availableVersions,
            'selectedOrganization' => $selectedOrganization,
            'organizationUnits' => $organizationUnits,
            'organizations' => $organizations->map(fn ($org) => [
                'id' => $org->id,
                'code' => $org->code,
                'name_en' => $org->name_en,
                'name_am' => $org->name_am,
            ])->values(),
            'unitTypes' => OrganizationUnitTypeModel::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get(['id', 'code', 'name_en', 'name_am']),
            'can' => [
                'viewAny' => $user?->can('viewAny', OrganizationUnit::class) ?? false,
                'create' => $user?->can('create', OrganizationUnit::class) ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', OrganizationUnit::class);

        $user = Auth::user();
        $accessibleOrgIds = $user?->accessibleOrganizationIds() ?? [];

        $orgQuery = Organization::query()->orderBy('name_en');

        if (! empty($accessibleOrgIds)) {
            $orgQuery->whereIn('id', $accessibleOrgIds);
        }

        $selectedOrg = null;
        $parentUnits = [];

        if ($request->filled('organization_id')) {
            $selectedOrg = Organization::query()->find(
                $request->get('organization_id'),
                ['id', 'code', 'name_en', 'name_am'],
            );
        }

        if ($selectedOrg !== null) {
            $treeService = app(OrganizationUnitTreeService::class);
            $parentUnits = $treeService->optionsForOrganization($selectedOrg->id);
        }

        return Inertia::render('OrganizationUnits/Create', [
            'organizations' => $orgQuery->get(['id', 'name_en', 'name_am', 'code']),
            'selectedOrg' => $selectedOrg,
            'parentUnits' => $parentUnits,
            'unitTypes' => OrganizationUnitTypeModel::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get(['id', 'code', 'name_en', 'name_am']),
            'statusOptions' => array_map(
                fn ($c) => ['value' => $c->value, 'label' => ucfirst($c->value)],
                OrganizationUnitStatus::cases(),
            ),
        ]);
    }

    public function store(StoreOrganizationUnitRequest $request, CreateOrganizationUnitAction $action): RedirectResponse
    {
        $unit = $action->execute($request->validated(), $request->user());

        return redirect()->route('organization-units.show', $unit)
            ->with('success', __('organization-units.created_successfully'));
    }

    public function show(OrganizationUnit $organizationUnit): Response
    {
        $this->authorize('view', $organizationUnit);

        $organizationUnit->load([
            'organization:id,name_en,name_am,code',
            'parent:id,name_en,code',
            'children',
            'relationships',
        ]);

        $organizationUnit->loadCount('children');

        // Owner/host pair for units operating inside another organization —
        // drives the hosted-unit banner and explains position code composition.
        $resolvedCodeContext = app(PositionCodeContextResolver::class)
            ->resolve($organizationUnit->organization_id, $organizationUnit->id);

        $hostedContext = null;

        if ($resolvedCodeContext['host_organization_id'] !== null) {
            $pair = Organization::query()
                ->whereIn('id', array_filter([
                    $resolvedCodeContext['owner_organization_id'],
                    $resolvedCodeContext['host_organization_id'],
                ]))
                ->get(['id', 'code', 'name_en', 'name_am'])
                ->keyBy('id');

            $hostedContext = [
                'owner_organization' => $pair->get($resolvedCodeContext['owner_organization_id'])?->only(['id', 'code', 'name_en', 'name_am']),
                'host_organization' => $pair->get($resolvedCodeContext['host_organization_id'])?->only(['id', 'code', 'name_en', 'name_am']),
            ];
        }

        return Inertia::render('OrganizationUnits/Show', [
            'unit' => (new OrganizationUnitResource($organizationUnit))->resolve(request()),
            'hostedContext' => $hostedContext,
            'relationships' => OrganizationUnitRelationshipResource::collection($organizationUnit->relationships)->resolve(request()),
            'relationshipOptions' => [
                'targetTypes' => array_map(
                    fn (RelationshipTargetType $case) => ['value' => $case->value, 'label' => $case->label()],
                    [RelationshipTargetType::Organization, RelationshipTargetType::OrganizationUnit],
                ),
                'relationshipTypes' => array_map(
                    fn (OrganizationRelationshipType $case) => ['value' => $case->value, 'label' => $case->label()],
                    OrganizationRelationshipType::cases(),
                ),
                'statuses' => array_map(
                    fn (RelationshipStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                    RelationshipStatus::cases(),
                ),
                'organizations' => Organization::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code']),
                'institutionOffices' => InstitutionOffice::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'office_code']),
                'organizationUnits' => OrganizationUnit::query()->whereKeyNot($organizationUnit->id)->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code']),
            ],
            'can' => [
                'manageRelationships' => Auth::user()?->can('relationships.create') ?? false,
                'updateRelationships' => Auth::user()?->can('relationships.update') ?? false,
                'deleteRelationships' => Auth::user()?->can('relationships.delete') ?? false,
            ],
        ]);
    }

    public function edit(OrganizationUnit $organizationUnit, OrganizationUnitTreeService $treeService): Response
    {
        $this->authorize('update', $organizationUnit);

        $organizationUnit->load(['organization:id,name_en,name_am,code', 'parent:id,name_en,code']);

        return Inertia::render('OrganizationUnits/Edit', [
            'unit' => (new OrganizationUnitResource($organizationUnit))->resolve(request()),
            'unitTypes' => OrganizationUnitTypeModel::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get(['id', 'code', 'name_en', 'name_am']),
            'statusOptions' => array_map(
                fn ($c) => ['value' => $c->value, 'label' => ucfirst($c->value)],
                OrganizationUnitStatus::cases(),
            ),
            'parentOptions' => $treeService->optionsForOrganization($organizationUnit->organization_id),
        ]);
    }

    public function update(UpdateOrganizationUnitRequest $request, OrganizationUnit $organizationUnit, UpdateOrganizationUnitAction $action): RedirectResponse
    {
        $unit = $action->execute($organizationUnit, $request->validated(), $request->user());

        return redirect()->route('organization-units.show', $unit)
            ->with('success', __('organization-units.updated_successfully'));
    }

    public function archive(Request $request, OrganizationUnit $organizationUnit, ArchiveOrganizationUnitAction $action): RedirectResponse
    {
        $this->authorize('archive', $organizationUnit);

        $action->execute($organizationUnit, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return redirect()->route('organization-units.index')
            ->with('success', __('recycle-bin.deleted_successfully'));
    }

    public function restore(Request $request, string $organizationUnit, RestoreOrganizationUnitAction $action): RedirectResponse
    {
        /** @var OrganizationUnit|null $unit */
        $unit = OrganizationUnit::withTrashed()->findOrFail($organizationUnit);

        $this->authorize('restore', $unit);

        $action->execute($unit, $request->user(), $request);

        return redirect()->route('organization-units.show', $unit)
            ->with('success', __('recycle-bin.restored_successfully'));
    }

    public function copyStructure(
        StoreOrganizationUnitStructureCopyRequest $request,
        CopyOrganizationUnitStructureAction $action,
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return to_route('organization-units.index')
            ->with('success', __('organization-units.structure_copied'));
    }

    public function options(Organization $organization, OrganizationUnitTreeService $treeService, OrganizationScopeService $scopeService): JsonResponse
    {
        abort_unless($scopeService->canAccess(request()->user(), $organization), 403);

        return response()->json($treeService->optionsForOrganization($organization->id));
    }

    public function tree(Organization $organization, OrganizationUnitTreeService $treeService, OrganizationScopeService $scopeService): JsonResponse
    {
        $this->authorize('viewAny', OrganizationUnit::class);
        abort_unless($scopeService->canAccess(request()->user(), $organization), 403);

        return response()->json($treeService->buildTree($organization->id));
    }
}
