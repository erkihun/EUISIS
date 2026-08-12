<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Positions\ArchivePositionAction;
use App\Actions\Positions\CreatePositionAction;
use App\Actions\Positions\RestorePositionAction;
use App\Actions\Positions\UpdatePositionAction;
use App\Actions\Vacancy\ApprovePositionEstablishmentAction;
use App\Enums\AssignmentStatus;
use App\Enums\EstablishmentStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OccupancyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\GradeLevel;
use App\Models\HierarchyVersion;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Services\CodeGeneration\PositionCodeContextResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function status(Request $request, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('viewAny', Position::class);

        $user = $request->user();
        $accessibleOrganizationIds = $organizationScopeService->accessibleOrganizationIds($user);

        $baseQuery = Position::query()
            ->when($accessibleOrganizationIds->isNotEmpty(), fn ($query) => $query->whereIn('organization_id', $accessibleOrganizationIds))
            ->when($request->string('search')->toString() !== '', function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($nested) use ($search): void {
                    $nested->where('job_position_code', ci_like_operator(), "%{$search}%")
                        ->orWhere('old_code', ci_like_operator(), "%{$search}%")
                        ->orWhere('title_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('title_am', ci_like_operator(), "%{$search}%")
                        ->orWhere('bpr_name', ci_like_operator(), "%{$search}%")
                        ->orWhere('grade_level', ci_like_operator(), "%{$search}%")
                        ->orWhere('job_family', ci_like_operator(), "%{$search}%");
                });
            })
            ->when($request->string('organization_id')->toString() !== '', fn ($query) => $query->where('organization_id', $request->string('organization_id')->toString()))
            ->when($request->string('organization_unit_id')->toString() !== '', fn ($query) => $query->where('organization_unit_id', $request->string('organization_unit_id')->toString()))
            ->when($request->string('job_family')->toString() !== '', fn ($query) => $query->where('job_family', $request->string('job_family')->toString()))
            ->when($request->string('grade_level')->toString() !== '', fn ($query) => $query->where('grade_level', $request->string('grade_level')->toString()))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        $summaryPositions = (clone $baseQuery)
            ->with([
                'organization:id,name_en,name_am',
                'organizationUnit:id,name_en,name_am',
            ])
            ->withCount([
                'assignments as active_assignments_count' => fn ($query) => $query
                    ->where('assignment_status', AssignmentStatus::Active->value)
                    ->where('is_current', true),
            ])
            ->orderBy('organization_id')
            ->orderBy('organization_unit_id')
            ->orderBy('job_position_code')
            ->get();

        $establishmentsByPosition = PositionEstablishment::query()
            ->where('status', EstablishmentStatus::Approved->value)
            ->when($accessibleOrganizationIds->isNotEmpty(), fn ($query) => $query->whereIn('organization_id', $accessibleOrganizationIds))
            ->withCount([
                'occupancies as filled_positions' => fn ($query) => $query->where('status', OccupancyStatus::Active->value),
            ])
            ->get(['id', 'establishment_number', 'position_id', 'approved_slots'])
            ->groupBy('position_id');

        $attachStatus = function (Position $position) use ($establishmentsByPosition): void {
            $establishments = $establishmentsByPosition->get($position->id, collect());
            $approvedSlots = (int) $establishments->sum('approved_slots');
            $establishmentFilled = (int) $establishments->sum('filled_positions');
            $activeAssignments = (int) $position->active_assignments_count;

            $position->status_total_positions = $approvedSlots > 0 ? $approvedSlots : 1;
            $position->status_filled_positions = $establishmentFilled > 0 ? $establishmentFilled : min($activeAssignments, $position->status_total_positions);
            $position->status_establishment_number = $establishments->pluck('establishment_number')->filter()->implode(', ');
        };

        $summaryPositions->each($attachStatus);

        $paginatedPositions = (clone $baseQuery)
            ->with([
                'organization:id,name_en,name_am',
                'organizationUnit:id,name_en,name_am',
            ])
            ->withCount([
                'assignments as active_assignments_count' => fn ($query) => $query
                    ->where('assignment_status', AssignmentStatus::Active->value)
                    ->where('is_current', true),
            ])
            ->orderBy('organization_id')
            ->orderBy('organization_unit_id')
            ->orderBy('job_position_code')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $paginatedPositions->getCollection()->each($attachStatus);

        $organizations = Organization::query()
            ->when($accessibleOrganizationIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $accessibleOrganizationIds))
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am']);

        $organizationUnits = OrganizationUnit::query()
            ->when($accessibleOrganizationIds->isNotEmpty(), fn ($query) => $query->whereIn('organization_id', $accessibleOrganizationIds))
            ->when($request->string('organization_id')->toString() !== '', fn ($query) => $query->where('organization_id', $request->string('organization_id')->toString()))
            ->orderBy('name_en')
            ->get(['id', 'organization_id', 'name_en', 'name_am']);

        $mapPosition = function (Position $position) use ($establishmentsByPosition): array {
            $filled = (int) $position->status_filled_positions;
            $total = (int) $position->status_total_positions;

            return [
                'id' => $position->id,
                'position_id' => $position->id,
                'establishment_id' => $establishmentsByPosition->get($position->id, collect())->first()?->id,
                'establishment_number' => $position->status_establishment_number,
                'job_position_code' => $position->job_position_code,
                'title_en' => $position->title_en,
                'title_am' => $position->title_am,
                'grade_level' => $position->grade_level,
                'job_family' => $position->job_family,
                'organization_name_en' => $position->organization?->name_en,
                'organization_name_am' => $position->organization?->name_am,
                'department_name_en' => $position->organizationUnit?->name_en ?? $position->organization?->name_en,
                'department_name_am' => $position->organizationUnit?->name_am ?? $position->organization?->name_am,
                'is_active' => (bool) $position->is_active,
                'total_positions' => $total,
                'filled_positions' => $filled,
                'vacant_positions' => max(0, $total - $filled),
            ];
        };

        $positions = $paginatedPositions->through($mapPosition);

        $jobFamilies = (clone $baseQuery)
            ->whereNotNull('job_family')
            ->distinct()
            ->orderBy('job_family')
            ->pluck('job_family')
            ->filter()
            ->values()
            ->all();

        return Inertia::render('Positions/Status', [
            'summary' => [
                'total_positions' => (int) $summaryPositions->sum('status_total_positions'),
                'filled_positions' => (int) $summaryPositions->sum('status_filled_positions'),
                'vacant_positions' => max(0, (int) $summaryPositions->sum('status_total_positions') - (int) $summaryPositions->sum('status_filled_positions')),
            ],
            'positions' => [
                'data' => $positions->items(),
                'meta' => [
                    'current_page' => $positions->currentPage(),
                    'last_page' => $positions->lastPage(),
                    'per_page' => $positions->perPage(),
                    'total' => $positions->total(),
                    'from' => $positions->firstItem(),
                    'to' => $positions->lastItem(),
                ],
            ],
            'organizations' => $organizations,
            'organizationUnits' => $organizationUnits,
            'jobFamilies' => $jobFamilies,
            'filters' => $request->only(['search', 'organization_id', 'organization_unit_id', 'job_family', 'grade_level', 'is_active', 'per_page']),
        ]);
    }

    public function index(Request $request, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('viewAny', Position::class);

        $user = $request->user();
        $accessibleOrganizationIds = $organizationScopeService->accessibleOrganizationIds($user);

        // Build organization tree
        $orgQuery = Organization::query()
            ->with(['type:id,name_en,name_am,code'])
            ->withCount(['organizationUnits' => fn ($q) => $q->whereNull('deleted_at')])
            ->orderBy('name_en');

        if ($accessibleOrganizationIds->isNotEmpty()) {
            $orgQuery->whereIn('id', $accessibleOrganizationIds);
        }

        $organizations = $orgQuery->get([
            'id', 'organization_type_id', 'code', 'name_en', 'name_am',
            'status', 'logo_path', 'effective_from',
        ]);

        $publishedVersion = HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published)
            ->latest('effective_from')
            ->first(['id']);

        $hasPublishedHierarchy = $publishedVersion !== null;
        $orgMap = $organizations->keyBy('id');

        if ($hasPublishedHierarchy) {
            $edges = OrganizationEdge::query()
                ->where('hierarchy_version_id', $publishedVersion->id)
                ->get(['parent_organization_id', 'child_organization_id']);

            $childrenMap = [];
            $edgeChildIds = [];
            foreach ($edges as $edge) {
                $pid = $edge->parent_organization_id;
                $cid = $edge->child_organization_id;
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
        $selectedUnit = null;
        $positions = collect();

        if ($request->filled('organization_id')) {
            $orgId = $request->string('organization_id')->toString();

            if ($accessibleOrganizationIds->isNotEmpty() && ! $accessibleOrganizationIds->contains($orgId)) {
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
                $allUnits = OrganizationUnit::query()
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                    ->orderBy('sort_order')
                    ->orderBy('name_en')
                    ->get(['id', 'code', 'name_en', 'name_am', 'parent_unit_id']);

                $buildUnitTree = function (?string $parentId, int $depth) use (&$buildUnitTree, $allUnits): array {
                    return $allUnits
                        ->filter(fn ($u) => $u->parent_unit_id === $parentId)
                        ->map(fn ($u) => [
                            'id' => $u->id,
                            'code' => $u->code,
                            'name_en' => $u->name_en,
                            'name_am' => $u->name_am,
                            'depth' => $depth,
                            'children' => $buildUnitTree($u->id, $depth + 1),
                        ])
                        ->values()
                        ->all();
                };

                $organizationUnits = $buildUnitTree(null, 0);

                $unitId = $request->string('organization_unit_id')->toString() ?: null;
                if ($unitId) {
                    $unitModel = $allUnits->firstWhere('id', $unitId);
                    if ($unitModel) {
                        $selectedUnit = [
                            'id' => $unitModel->id,
                            'name_en' => $unitModel->name_en,
                            'name_am' => $unitModel->name_am,
                        ];
                    }
                }

                $positions = Position::query()
                    ->with('organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'occupation:id,isco_code,name_en,name_am')
                    ->withCount('assignments')
                    ->where('organization_id', $orgId)
                    ->when($unitId, fn ($q) => $q->where('organization_unit_id', $unitId))
                    ->when($request->string('search')->toString() !== '', function ($query) use ($request): void {
                        $search = $request->string('search')->toString();
                        $query->where(function ($nested) use ($search): void {
                            $nested->where('job_position_code', ci_like_operator(), "%{$search}%")
                                ->orWhere('old_code', ci_like_operator(), "%{$search}%")
                                ->orWhere('title_en', ci_like_operator(), "%{$search}%")
                                ->orWhere('title_am', ci_like_operator(), "%{$search}%")
                                ->orWhere('bpr_name', ci_like_operator(), "%{$search}%");
                        });
                    })
                    ->when($request->string('job_family')->toString() !== '', fn ($q) => $q->where('job_family', $request->string('job_family')->toString()))
                    ->when($request->string('grade_level')->toString() !== '', fn ($q) => $q->where('grade_level', $request->string('grade_level')->toString()))
                    ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
                    ->orderBy('job_position_code')
                    ->get();
            }
        }

        // Load establishment data for every position in the current view
        $positionIds = $positions->pluck('id');
        $establishmentsByPosition = $positionIds->isNotEmpty()
            ? PositionEstablishment::query()
                ->whereIn('position_id', $positionIds)
                ->whereIn('status', [EstablishmentStatus::Draft->value, EstablishmentStatus::Approved->value])
                ->get(['id', 'position_id', 'status', 'approved_slots', 'establishment_number'])
                ->groupBy('position_id')
                ->map(fn ($group) => $group->sortBy(fn ($e) => $e->status->value === EstablishmentStatus::Approved->value ? 0 : 1)->first())
            : collect();

        $positionData = collect(PositionResource::collection($positions)->resolve())
            ->map(function (array $pos) use ($establishmentsByPosition): array {
                $est = $establishmentsByPosition->get($pos['id']);
                $pos['establishment'] = $est ? [
                    'id' => $est->id,
                    'status' => $est->status->value,
                    'approved_slots' => $est->approved_slots,
                    'establishment_number' => $est->establishment_number,
                ] : null;

                return $pos;
            })
            ->values()
            ->all();

        return Inertia::render('Positions/Index', [
            'organizationTree' => $organizationTree,
            'hasPublishedHierarchy' => $hasPublishedHierarchy,
            'selectedOrganization' => $selectedOrganization,
            'organizationUnits' => $organizationUnits,
            'selectedUnit' => $selectedUnit,
            'positions' => $positionData,
            'filters' => $request->only(['search', 'job_family', 'grade_level', 'is_active']),
            'can' => [
                'create' => $user?->can('create', Position::class) ?? false,
                'approve_establishment' => $user?->can('position-establishments.approve') ?? false,
            ],
        ]);
    }

    public function create(
        Request $request,
        OrganizationScopeService $organizationScopeService,
        PositionCodeContextResolver $positionCodeContextResolver,
    ): Response {
        $this->authorize('create', Position::class);

        $organizationId = $request->string('organization_id')->toString() ?: null;
        $organizationUnitId = $request->string('organization_unit_id')->toString() ?: null;

        $organizationUnits = $organizationId
            ? OrganizationUnit::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am', 'code', 'organization_unit_type_id'])
                ->map(function (OrganizationUnit $unit) use ($organizationId, $positionCodeContextResolver): array {
                    $resolved = $positionCodeContextResolver->resolve($organizationId, $unit->id);

                    $ownerOrganization = null;
                    $hostOrganization = null;

                    // Only hosted units carry the owner/host pair to the form.
                    if ($resolved['host_organization_id'] !== null) {
                        $pair = Organization::query()
                            ->whereIn('id', array_filter([$resolved['owner_organization_id'], $resolved['host_organization_id']]))
                            ->get(['id', 'code', 'name_en', 'name_am'])
                            ->keyBy('id');

                        $ownerOrganization = $pair->get($resolved['owner_organization_id'])?->only(['id', 'code', 'name_en', 'name_am']);
                        $hostOrganization = $pair->get($resolved['host_organization_id'])?->only(['id', 'code', 'name_en', 'name_am']);
                    }

                    return [
                        'id' => $unit->id,
                        'name_en' => $unit->name_en,
                        'name_am' => $unit->name_am,
                        'code' => $unit->code,
                        'organization_unit_type_id' => $unit->organization_unit_type_id,
                        'owner_organization' => $ownerOrganization,
                        'host_organization' => $hostOrganization,
                    ];
                })
                ->all()
            : [];

        $occupations = Occupation::query()
            ->whereNull('deleted_at')
            ->orderBy('isco_code')
            ->get(['id', 'isco_code', 'name_en', 'name_am'])
            ->toArray();

        $gradeLevels = GradeLevel::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return Inertia::render('Positions/Create', [
            'organizations' => Organization::query()
                ->whereIn('id', $organizationScopeService->accessibleOrganizationIds(request()->user()))
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am']),
            'organizationUnits' => $organizationUnits,
            'occupations' => $occupations,
            'gradeLevels' => $gradeLevels,
            'selectedOrganizationId' => $organizationId,
            'selectedOrganizationUnitId' => $organizationUnitId,
        ]);
    }

    public function store(StorePositionRequest $request, CreatePositionAction $action): RedirectResponse
    {
        $position = $action->execute($request->validated(), $request->user());

        return to_route('positions.show', $position)
            ->with('flash', ['message' => __('positions.created_successfully'), 'type' => 'success']);
    }

    public function show(Position $position): Response
    {
        $this->authorize('view', $position);

        $position->load('organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am,code', 'occupation:id,isco_code,name_en,name_am');
        $position->loadCount('assignments');

        return Inertia::render('Positions/Show', [
            'position' => (new PositionResource($position))->resolve(),
        ]);
    }

    public function edit(Position $position, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('update', $position);

        $position->load('organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am,code', 'occupation:id,isco_code,name_en,name_am');

        $organizationUnits = $position->organization_id
            ? OrganizationUnit::query()
                ->where('organization_id', $position->organization_id)
                ->whereNull('deleted_at')
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am', 'code', 'organization_unit_type_id'])
                ->toArray()
            : [];

        $occupations = Occupation::query()
            ->whereNull('deleted_at')
            ->orderBy('isco_code')
            ->get(['id', 'isco_code', 'name_en', 'name_am'])
            ->toArray();

        $gradeLevels = GradeLevel::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return Inertia::render('Positions/Edit', [
            'position' => (new PositionResource($position))->resolve(),
            'organizations' => Organization::query()
                ->whereIn('id', $organizationScopeService->accessibleOrganizationIds(request()->user()))
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am']),
            'organizationUnits' => $organizationUnits,
            'occupations' => $occupations,
            'gradeLevels' => $gradeLevels,
        ]);
    }

    public function update(UpdatePositionRequest $request, Position $position, UpdatePositionAction $action): RedirectResponse
    {
        $action->execute($position, $request->validated(), $request->user());

        return to_route('positions.show', $position)
            ->with('flash', ['message' => __('positions.updated_successfully'), 'type' => 'success']);
    }

    public function archive(Request $request, Position $position, ArchivePositionAction $action): RedirectResponse
    {
        $this->authorize('archive', $position);

        $action->execute($position, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('positions.index')->with('flash', ['message' => __('recycle-bin.deleted_successfully'), 'type' => 'success']);
    }

    public function approveEstablishment(
        Position $position,
        ApprovePositionEstablishmentAction $action,
    ): RedirectResponse {
        $this->authorize('create', PositionEstablishment::class);

        /** @var User $actor */
        $actor = Auth::user();

        $establishment = PositionEstablishment::query()
            ->where('position_id', $position->id)
            ->where('organization_id', $position->organization_id)
            ->whereIn('status', [EstablishmentStatus::Draft->value, EstablishmentStatus::Approved->value])
            ->first();

        if ($establishment === null) {
            $establishment = PositionEstablishment::create([
                'establishment_number' => 'EST-'.now()->format('Ym').'-'.strtoupper(substr(str_replace('-', '', $position->id), 0, 6)),
                'organization_id' => $position->organization_id,
                'organization_unit_id' => $position->organization_unit_id,
                'position_id' => $position->id,
                'approved_slots' => 1,
                'effective_from' => now()->toDateString(),
                'status' => EstablishmentStatus::Draft->value,
            ]);
        }

        if ($establishment->status->value === EstablishmentStatus::Approved->value) {
            return back()->with('flash', ['message' => __('positionEstablishments.alreadyApproved'), 'type' => 'info']);
        }

        $this->authorize('approve', $establishment);

        $action->execute($establishment, $actor);

        return back()->with('flash', ['message' => __('positionEstablishments.approved'), 'type' => 'success']);
    }

    public function restore(Request $request, string $position, RestorePositionAction $action): RedirectResponse
    {
        $position = Position::query()->withTrashed()->findOrFail($position);

        $this->authorize('restore', $position);

        $action->execute($position, $request->user(), $request);

        return back()->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }
}
