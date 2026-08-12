<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Actions\Employees\RegisterEmployeeAction;
use App\Actions\Transfers\RequestEmployeeTransferAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeStoreRequest;
use App\Http\Requests\EmployeeTransferRequest;
use App\Http\Requests\EmployeeUpdateRequest;
use App\Http\Resources\EmployeeDetailResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('viewAny', Employee::class);

        $user = $request->user();
        $accessibleOrganizationIds = $organizationScopeService->accessibleOrganizationIds($user);

        $orgQuery = Organization::query()
            ->with(['type:id,name_en,name_am,code'])
            ->withCount(['organizationUnits' => fn ($query) => $query->whereNull('deleted_at')])
            ->orderBy('name_en');

        if ($accessibleOrganizationIds->isNotEmpty()) {
            $orgQuery->whereIn('id', $accessibleOrganizationIds);
        }

        $organizations = $orgQuery->get([
            'id',
            'organization_type_id',
            'code',
            'name_en',
            'name_am',
            'status',
            'logo_path',
            'effective_from',
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
                $parentId = $edge->parent_organization_id;
                $childId = $edge->child_organization_id;

                if ($orgMap->has($parentId) && $orgMap->has($childId)) {
                    $childrenMap[$parentId][] = $childId;
                    $edgeChildIds[] = $childId;
                }
            }

            $edgeChildIds = array_unique($edgeChildIds);

            $buildOrgNode = function (string $orgId, int $depth) use (&$buildOrgNode, $orgMap, $childrenMap): ?array {
                $organization = $orgMap->get($orgId);

                if (! $organization) {
                    return null;
                }

                $children = [];
                foreach ($childrenMap[$orgId] ?? [] as $childId) {
                    $child = $buildOrgNode($childId, $depth + 1);

                    if ($child !== null) {
                        $children[] = $child;
                    }
                }

                return $this->organizationTreeNode($organization, $depth, $children);
            };

            $organizationTree = $organizations
                ->filter(fn (Organization $organization): bool => ! in_array($organization->id, $edgeChildIds, true))
                ->map(fn (Organization $organization): ?array => $buildOrgNode($organization->id, 0))
                ->filter()
                ->values()
                ->all();
        } else {
            $organizationTree = $organizations
                ->map(fn (Organization $organization): array => $this->organizationTreeNode($organization, 0))
                ->values()
                ->all();
        }

        $selectedOrganization = null;
        $positions = collect();
        $selectedPosition = null;
        $selectedOrganizationId = $request->string('organization_id')->toString() ?: null;
        $selectedPositionId = $request->string('position_id')->toString() ?: null;

        if ($selectedOrganizationId !== null) {
            if ($accessibleOrganizationIds->isNotEmpty() && ! $accessibleOrganizationIds->contains($selectedOrganizationId)) {
                abort(403);
            }

            $selectedOrganization = Organization::query()
                ->with(['type:id,name_en,name_am,code'])
                ->withCount(['organizationUnits' => fn ($query) => $query->whereNull('deleted_at')])
                ->find($selectedOrganizationId, [
                    'id',
                    'organization_type_id',
                    'code',
                    'name_en',
                    'name_am',
                    'status',
                    'logo_path',
                    'effective_from',
                ]);

            $positions = Position::query()
                ->where('organization_id', $selectedOrganizationId)
                ->where('is_active', true)
                ->orderBy('title_en')
                ->get(['id', 'job_position_code', 'title_en', 'title_am', 'organization_id', 'organization_unit_id']);

            if ($selectedPositionId !== null) {
                $selectedPosition = $positions
                    ->firstWhere('id', $selectedPositionId);
            }
        }

        $employeesPaginated = Employee::query()
            ->with(['currentAssignment.organization', 'currentAssignment.organizationUnit', 'currentAssignment.position'])
            ->withCount('employeeDuplicateFlags')
            ->when(
                ! $user->hasRole(['Super Admin', 'City Admin']),
                fn ($query) => $query->whereHas('currentAssignment', fn ($assignmentQuery) => $assignmentQuery->whereIn('organization_id', $accessibleOrganizationIds))
            )
            ->when(
                $selectedOrganizationId !== null,
                fn ($query) => $query->whereHas('currentAssignment', fn ($assignmentQuery) => $assignmentQuery->where('organization_id', $selectedOrganizationId))
            )
            ->when(
                $selectedPosition !== null,
                fn ($query) => $query->whereHas('currentAssignment', fn ($assignmentQuery) => $assignmentQuery->where('position_id', $selectedPosition->id))
            )
            ->when($request->string('search')->toString() !== '', function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($nested) use ($search): void {
                    $nested->where('employee_number', ci_like_operator(), "%{$search}%")
                        ->orWhere('full_name', ci_like_operator(), "%{$search}%")
                        ->orWhere('phone', ci_like_operator(), "%{$search}%");
                });
            })
            ->when($request->string('status')->toString() !== '', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderBy('full_name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Employees/Index', [
            'organizationTree' => $organizationTree,
            'hasPublishedHierarchy' => $hasPublishedHierarchy,
            'selectedOrganization' => $selectedOrganization,
            'positions' => $positions->map(fn (Position $position): array => [
                'id' => $position->id,
                'job_position_code' => $position->job_position_code,
                'title_en' => $position->title_en,
                'title_am' => $position->title_am,
                'organization_id' => $position->organization_id,
                'organization_unit_id' => $position->organization_unit_id,
            ])->values()->all(),
            'selectedPosition' => $selectedPosition ? [
                'id' => $selectedPosition->id,
                'job_position_code' => $selectedPosition->job_position_code,
                'title_en' => $selectedPosition->title_en,
                'title_am' => $selectedPosition->title_am,
                'organization_unit_id' => $selectedPosition->organization_unit_id,
            ] : null,
            'employees' => EmployeeResource::collection($employeesPaginated->getCollection())->resolve(),
            'employees_pagination' => [
                'current_page' => $employeesPaginated->currentPage(),
                'last_page' => $employeesPaginated->lastPage(),
                'per_page' => $employeesPaginated->perPage(),
                'total' => $employeesPaginated->total(),
            ],
            'filters' => $request->only(['search', 'status', 'organization_id', 'position_id']),
            'can' => [
                'create' => $user?->can('create', Employee::class) ?? false,
            ],
        ]);
    }

    public function create(OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('create', Employee::class);

        $request = request();
        $accessibleOrganizationIds = $organizationScopeService->accessibleOrganizationIds($request->user());
        $selectedOrganizationId = $request->string('organization_id')->toString() ?: null;
        $selectedPositionId = $request->string('position_id')->toString() ?: null;
        $selectedOrganizationUnitId = $request->string('organization_unit_id')->toString() ?: null;

        if ($selectedOrganizationId !== null && $accessibleOrganizationIds->isNotEmpty() && ! $accessibleOrganizationIds->contains($selectedOrganizationId)) {
            abort(403);
        }

        $selectedPosition = $selectedPositionId
            ? Position::query()
                ->where('is_active', true)
                ->whereDoesntHave('assignments', fn ($q) => $q
                    ->where('is_current', true)
                    ->where('assignment_status', AssignmentStatus::Active)
                )
                ->when($accessibleOrganizationIds->isNotEmpty(), fn ($query) => $query->whereIn('organization_id', $accessibleOrganizationIds))
                ->when($selectedOrganizationId !== null, fn ($query) => $query->where('organization_id', $selectedOrganizationId))
                ->firstWhere('id', $selectedPositionId)
            : null;

        if ($selectedPosition !== null) {
            $selectedOrganizationId ??= $selectedPosition->organization_id;
            $selectedOrganizationUnitId ??= $selectedPosition->organization_unit_id;
        }

        if ($selectedOrganizationId !== null && $accessibleOrganizationIds->isNotEmpty() && ! $accessibleOrganizationIds->contains($selectedOrganizationId)) {
            abort(403);
        }

        // Only active structures may receive a new employee assignment.
        $organizationQuery = Organization::query()
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name_en');

        if ($accessibleOrganizationIds->isNotEmpty()) {
            $organizationQuery->whereIn('id', $accessibleOrganizationIds);
        }

        $organizationUnitQuery = OrganizationUnit::query()
            ->where('status', OrganizationUnitStatus::Active->value)
            ->whereNull('deleted_at')
            ->orderBy('name_en');

        $positionQuery = Position::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereDoesntHave('assignments', fn ($q) => $q
                ->where('is_current', true)
                ->where('assignment_status', AssignmentStatus::Active)
            )
            ->orderBy('title_en');

        if ($accessibleOrganizationIds->isNotEmpty()) {
            $organizationUnitQuery->whereIn('organization_id', $accessibleOrganizationIds);
            $positionQuery->whereIn('organization_id', $accessibleOrganizationIds);
        }

        return Inertia::render('Employees/Create', [
            'organizations' => $organizationQuery
                ->get(['id', 'name_en']),
            'organizationUnits' => $organizationUnitQuery
                ->get(['id', 'organization_id', 'name_en', 'name_am', 'code']),
            'hierarchyVersions' => HierarchyVersion::query()->orderByDesc('effective_from')->get(['id', 'version_name', 'status']),
            'positions' => $positionQuery
                ->get(['id', 'job_position_code', 'title_en', 'title_am', 'organization_id', 'organization_unit_id']),
            'selectedOrganizationId' => $selectedOrganizationId,
            'selectedOrganizationUnitId' => $selectedOrganizationUnitId,
            'selectedPositionId' => $selectedPositionId,
        ]);
    }

    private function organizationTreeNode(Organization $organization, int $depth, array $children = []): array
    {
        return [
            'id' => $organization->id,
            'code' => $organization->code,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
            'status' => $organization->status,
            'logo_url' => $organization->logo_url,
            'has_logo' => $organization->has_logo,
            'organization_units_count' => $organization->organization_units_count,
            'type' => $organization->type ? [
                'id' => $organization->type->id,
                'code' => $organization->type->code,
                'name_en' => $organization->type->name_en,
                'name_am' => $organization->type->name_am,
            ] : null,
            'depth' => $depth,
            'children' => $children,
        ];
    }

    public function edit(Employee $employee, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('update', $employee);

        $employee->load(['currentAssignment.organization', 'currentAssignment.position']);

        return Inertia::render('Employees/Edit', [
            'employee' => (new EmployeeDetailResource($employee))->resolve(),
            'positions' => Position::query()->where('is_active', true)->orderBy('title_en')->get(['id', 'title_en']),
        ]);
    }

    public function show(Employee $employee, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('view', $employee);

        $employee->load([
            'currentAssignment.organization',
            'currentAssignment.position',
            'assignments.organization',
            'assignments.position',
            'documents',
            'employeeDuplicateFlags.matchedEmployee',
            'transfers.fromOrganization',
            'transfers.toOrganization',
        ]);

        return Inertia::render('Employees/Show', [
            'employee' => (new EmployeeDetailResource($employee))->resolve(),
            'organizations' => Organization::query()
                ->whereIn('id', $organizationScopeService->accessibleOrganizationIds(request()->user()))
                ->orderBy('name_en')
                ->get(['id', 'name_en']),
        ]);
    }

    public function store(
        EmployeeStoreRequest $request,
        RegisterEmployeeAction $registerEmployeeAction,
        GenerateCodeAction $generateCodeAction,
    ): RedirectResponse {
        $this->authorize('create', Employee::class);

        $positionId = $request->string('position_id')->toString() !== ''
            ? $request->string('position_id')->toString()
            : null;
        $organizationUnitId = $request->string('organization_unit_id')->toString() !== ''
            ? $request->string('organization_unit_id')->toString()
            : null;

        if ($positionId !== null && $organizationUnitId === null) {
            $organizationUnitId = Position::query()
                ->whereKey($positionId)
                ->value('organization_unit_id');
        }

        if ($positionId === null && $request->string('position_title')->toString() !== '') {
            $position = Position::query()->where([
                'organization_id' => $request->string('organization_id')->toString(),
                'organization_unit_id' => $organizationUnitId,
                'title_en' => $request->string('position_title')->toString(),
            ])->first();

            if ($position === null) {
                $position = Position::query()->create([
                    'organization_id' => $request->string('organization_id')->toString(),
                    'organization_unit_id' => $organizationUnitId,
                    'title_en' => $request->string('position_title')->toString(),
                    'job_position_code' => $generateCodeAction->execute(
                        CodeRuleEntityType::Position,
                        [
                            'organization_id' => $request->string('organization_id')->toString(),
                            'organization_unit_id' => $organizationUnitId,
                        ],
                        $request->user(),
                        null,
                        'job_position_code',
                    ),
                    'is_active' => true,
                    'effective_from' => now()->toDateString(),
                ]);
            }

            $positionId = $position->id;
        }

        $employeeAttributes = $request->safe()->only([
            'employee_number',
            'first_name',
            'middle_name',
            'last_name',
            'name_en',
            'phone',
            'email',
            'date_of_birth',
            'gender',
            'status',
            'national_id',
        ]);

        $employeeAttributes['full_name'] = trim(
            implode(' ', array_filter([
                $request->string('first_name')->toString(),
                $request->string('middle_name')->toString(),
                $request->string('last_name')->toString(),
            ]))
        );

        $employee = $registerEmployeeAction->execute(
            $employeeAttributes,
            [
                'organization_id' => $request->string('organization_id')->toString(),
                'organization_unit_id' => $organizationUnitId,
                'hierarchy_version_id' => $request->string('hierarchy_version_id')->toString() ?: null,
                'position_id' => $positionId,
                'effective_from' => $request->date('effective_from')?->toDateString() ?? now()->toDateString(),
                'reason' => $request->input('reason'),
            ],
            $request->user(),
        );

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store(
                'employees/photos/'.$employee->id,
                'public'
            );
            $employee->update(['photo_path' => $path]);
        }

        return to_route('employees.show', $employee)
            ->with('flash', ['message' => __('Employee registered successfully.'), 'type' => 'success']);
    }

    public function update(
        EmployeeUpdateRequest $request,
        Employee $employee,
        WriteAuditLogAction $writeAuditLogAction,
    ): RedirectResponse {
        $this->authorize('update', $employee);

        $oldValues = $employee->toArray();

        $attributes = $request->safe()->except(['photo', 'remove_photo']);
        $attributes['full_name'] = trim(implode(' ', array_filter([
            $request->string('first_name')->toString(),
            $request->string('middle_name')->toString(),
            $request->string('last_name')->toString(),
        ])));

        if ($request->boolean('remove_photo') && $employee->photo_path) {
            Storage::disk('public')->delete($employee->photo_path);
            $attributes['photo_path'] = null;
        }

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                Storage::disk('public')->delete($employee->photo_path);
            }
            $attributes['photo_path'] = $request->file('photo')->store(
                'employees/photos/'.$employee->id,
                'public'
            );
        }

        $employee->update($attributes);

        $writeAuditLogAction->execute(
            AuditEventType::EmployeeUpdated,
            $request->user(),
            $employee->fresh(),
            $employee->currentAssignment?->organization_id,
            oldValues: $oldValues,
            newValues: $employee->fresh()->toArray(),
            request: $request,
        );

        return back()->with('flash', ['message' => __('Employee updated successfully.'), 'type' => 'success']);
    }

    public function transfer(
        EmployeeTransferRequest $request,
        Employee $employee,
        RequestEmployeeTransferAction $requestEmployeeTransferAction,
    ): RedirectResponse {
        $this->authorize('transfer', $employee);

        $transfer = $requestEmployeeTransferAction->execute(
            $employee,
            $request->string('organization_id')->toString(),
            $request->user(),
            $request->input('reason'),
            now()->toDateString(),
        );

        return to_route('transfers.dashboard')
            ->with('flash', ['message' => __('Transfer draft created.'), 'type' => 'success']);
    }
}
