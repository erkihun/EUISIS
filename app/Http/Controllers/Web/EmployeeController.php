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
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeStoreRequest;
use App\Http\Requests\EmployeeTransferRequest;
use App\Http\Requests\EmployeeUpdateRequest;
use App\Http\Resources\EmployeeDetailResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('viewAny', Employee::class);

        $user = $request->user();
        $allowedOrganizationIds = $organizationScopeService->allowedOrganizationIds($user);
        $isOrganizationScoped = ! $organizationScopeService->isUnrestricted($user);

        $orgQuery = Organization::query()
            ->orderBy('name_en');

        $organizationScopeService->applyOrganizationScope($orgQuery, $user, 'id');

        $organizations = $orgQuery->get([
            'id',
            'code',
            'name_en',
            'name_am',
            'status',
        ]);

        $organizationStructure = $this->organizationStructure($organizations, $user, $organizationScopeService);

        $selectedOrganization = null;
        $selectedPosition = null;
        $selectedOrganizationId = $request->string('organization_id')->toString()
            ?: ($isOrganizationScoped ? $organizations->first()?->id : null);
        $selectedPositionId = $request->string('position_id')->toString() ?: null;

        if ($selectedOrganizationId !== null) {
            if (! $organizationScopeService->canAccess($user, $selectedOrganizationId)) {
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

            if ($selectedPositionId !== null) {
                $selectedPositionQuery = Position::query()
                    ->where('organization_id', $selectedOrganizationId)
                    ->where('is_active', true)
                    ->whereKey($selectedPositionId);
                $organizationScopeService->applyOrganizationScope($selectedPositionQuery, $user);
                $selectedPosition = $selectedPositionQuery->first([
                    'id', 'job_position_code', 'title_en', 'title_am', 'organization_id', 'organization_unit_id',
                ]);
            }
        }

        $employeesPaginated = Employee::query()
            ->with(['currentAssignment.organization', 'currentAssignment.organizationUnit', 'currentAssignment.position'])
            ->withCount('employeeDuplicateFlags')
            ->when(
                $isOrganizationScoped,
                fn ($query) => $query->whereHas('currentAssignment', fn ($assignmentQuery) => $assignmentQuery->whereIn('organization_id', $allowedOrganizationIds))
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
            'organizationStructure' => $organizationStructure,
            'isOrganizationScoped' => $isOrganizationScoped,
            'selectedOrganization' => $selectedOrganization,
            'selectedPosition' => $selectedPosition ? [
                'id' => $selectedPosition->id,
                'job_position_code' => $selectedPosition->job_position_code,
                'title_en' => $selectedPosition->title_en,
                'title_am' => $selectedPosition->title_am,
                'organization_unit_id' => $selectedPosition->organization_unit_id,
                // Drives the disabled "Create Employee" affordance; the create
                // route re-checks this regardless of what the client does.
                'occupancy_status' => $this->positionIsOccupied($selectedPosition->id) ? 'occupied' : 'vacant',
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

    public function create(OrganizationScopeService $organizationScopeService): Response|RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $request = request();
        $user = $request->user();
        $selectedOrganizationId = $request->string('organization_id')->toString() ?: null;
        $selectedPositionId = $request->string('position_id')->toString() ?: null;
        $selectedOrganizationUnitId = $request->string('organization_unit_id')->toString() ?: null;

        if ($selectedOrganizationId !== null && ! $organizationScopeService->canAccess($user, $selectedOrganizationId)) {
            abort(403);
        }

        if ($selectedOrganizationUnitId !== null) {
            $selectedUnitOrganizationId = OrganizationUnit::query()->whereKey($selectedOrganizationUnitId)->value('organization_id');

            if ($selectedUnitOrganizationId !== null && ! $organizationScopeService->canAccess($user, $selectedUnitOrganizationId)) {
                abort(403);
            }
        }

        $selectedPositionQuery = Position::query()
            ->where('is_active', true)
            ->whereDoesntHave('assignments', fn ($q) => $q
                ->where('is_current', true)
                ->where('assignment_status', AssignmentStatus::Active)
            )
            ->when($selectedOrganizationId !== null, fn ($query) => $query->where('organization_id', $selectedOrganizationId));
        $organizationScopeService->applyOrganizationScope($selectedPositionQuery, $user);
        $selectedPosition = $selectedPositionId ? $selectedPositionQuery->firstWhere('id', $selectedPositionId) : null;

        if ($selectedPositionId !== null && $selectedPosition === null) {
            $selectedPositionOrganizationId = Position::query()->whereKey($selectedPositionId)->value('organization_id');

            if ($selectedPositionOrganizationId !== null && ! $organizationScopeService->canAccess($user, $selectedPositionOrganizationId)) {
                abort(403);
            }

            // In scope but unusable — almost always because someone already
            // holds it. Bounce back with a message instead of silently loading
            // the form with the position dropped.
            if ($this->positionIsOccupied($selectedPositionId)) {
                return back()->with('flash', [
                    'message' => __('validation.position_already_occupied'),
                    'type' => 'error',
                ]);
            }
        }

        if ($selectedPosition !== null) {
            $selectedOrganizationId ??= $selectedPosition->organization_id;
            $selectedOrganizationUnitId ??= $selectedPosition->organization_unit_id;
        }

        if ($selectedOrganizationId !== null && ! $organizationScopeService->canAccess($user, $selectedOrganizationId)) {
            abort(403);
        }

        // Only active structures may receive a new employee assignment.
        $organizationQuery = Organization::query()
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name_en');

        $organizationScopeService->applyOrganizationScope($organizationQuery, $user, 'id');

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

        $organizationScopeService->applyOrganizationScope($organizationUnitQuery, $user);
        $organizationScopeService->applyOrganizationScope($positionQuery, $user);

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
            // Resolved names for the read-only placement summary. Built server
            // side so the display never depends on the record happening to be
            // present in the selectable option lists.
            'placementContext' => $this->placementContext(
                $selectedOrganizationId,
                $selectedOrganizationUnitId,
                $selectedPosition,
            ),
        ]);
    }

    /**
     * Whether an active, current assignment already holds the position. Mirrors
     * the vacancy rule used by the create-page query and EmployeeStoreRequest.
     */
    private function positionIsOccupied(string $positionId): bool
    {
        return EmployeeAssignment::query()
            ->where('position_id', $positionId)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active)
            ->exists();
    }

    /**
     * Localized organization / unit / position names for a position-driven
     * create. Returns null when the page was opened without a position context.
     *
     * @return array<string, mixed>|null
     */
    private function placementContext(
        ?string $organizationId,
        ?string $organizationUnitId,
        ?Position $position,
    ): ?array {
        if ($position === null) {
            return null;
        }

        $organization = $organizationId !== null
            ? Organization::query()->find($organizationId, ['id', 'code', 'name_en', 'name_am'])
            : null;

        $unit = $organizationUnitId !== null
            ? OrganizationUnit::query()->find($organizationUnitId, ['id', 'code', 'name_en', 'name_am'])
            : null;

        return [
            'organization' => $organization ? [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
            ] : null,
            'organization_unit' => $unit ? [
                'id' => $unit->id,
                'code' => $unit->code,
                'name_en' => $unit->name_en,
                'name_am' => $unit->name_am,
            ] : null,
            'position' => [
                'id' => $position->id,
                'code' => $position->job_position_code,
                'name_en' => $position->title_en,
                'name_am' => $position->title_am,
            ],
        ];
    }

    private function organizationStructure(Collection $organizations, User $user, OrganizationScopeService $organizationScopeService): array
    {
        $unitQuery = OrganizationUnit::query()
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name_en');
        $organizationScopeService->applyOrganizationScope($unitQuery, $user);
        $unitsByOrganization = $unitQuery
            ->get(['id', 'organization_id', 'parent_unit_id', 'code', 'name_en', 'name_am', 'status'])
            ->groupBy('organization_id');

        $positionQuery = Position::query()
            ->whereNull('deleted_at')
            ->withExists(['assignments as is_occupied' => fn ($query) => $query
                ->where('is_current', true)
                ->where('assignment_status', AssignmentStatus::Active->value)])
            ->orderBy('title_en');
        $organizationScopeService->applyOrganizationScope($positionQuery, $user);
        $positionsByUnit = $positionQuery
            ->get(['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'title_en', 'title_am', 'is_active'])
            ->groupBy('organization_unit_id');

        return $organizations->map(function (Organization $organization) use ($unitsByOrganization, $positionsByUnit): array {
            $organizationUnits = $unitsByOrganization->get($organization->id, collect());
            $unitIds = $organizationUnits->pluck('id')->all();
            $unitsByParent = $organizationUnits
                ->groupBy(fn (OrganizationUnit $unit): string => $unit->parent_unit_id ?? 'root');
            $builtIds = [];

            $buildUnit = function (OrganizationUnit $unit) use (&$buildUnit, &$builtIds, $unitsByParent, $positionsByUnit): ?array {
                if (isset($builtIds[$unit->id])) {
                    return null;
                }

                $builtIds[$unit->id] = true;

                return [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'name_en' => $unit->name_en,
                    'name_am' => $unit->name_am,
                    'parent_unit_id' => $unit->parent_unit_id,
                    'status' => $unit->status instanceof \BackedEnum ? $unit->status->value : (string) $unit->status,
                    'positions' => $positionsByUnit->get($unit->id, collect())->map(fn (Position $position): array => [
                        'id' => $position->id,
                        'code' => $position->job_position_code,
                        'standard_name' => $position->title_en,
                        'standard_name_am' => $position->title_am,
                        'organization_unit_id' => $position->organization_unit_id,
                        'status' => $position->is_active ? 'active' : 'inactive',
                        'occupancy_status' => $position->is_occupied ? 'occupied' : 'vacant',
                    ])->values()->all(),
                    'children' => $unitsByParent->get($unit->id, collect())->map($buildUnit)->filter()->values()->all(),
                ];
            };

            $rootUnits = $organizationUnits
                ->filter(fn (OrganizationUnit $unit): bool => $unit->parent_unit_id === null || ! in_array($unit->parent_unit_id, $unitIds, true));
            $tree = $rootUnits->map($buildUnit)->filter()->values();
            $tree = $tree->concat($organizationUnits->reject(fn (OrganizationUnit $unit): bool => isset($builtIds[$unit->id]))
                ->map($buildUnit)->filter())->values();

            return [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'status' => $organization->status instanceof \BackedEnum ? $organization->status->value : (string) $organization->status,
                'units' => $tree->all(),
            ];
        })->values()->all();
    }

    public function edit(Employee $employee, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('update', $employee);

        $employee->load(['currentAssignment.organization', 'currentAssignment.position']);

        return Inertia::render('Employees/Edit', [
            'employee' => (new EmployeeDetailResource($employee))->resolve(),
            'positions' => $organizationScopeService
                ->applyOrganizationScope(Position::query()->where('is_active', true)->orderBy('title_en'), request()->user())
                ->get(['id', 'title_en']),
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
            ->with('flash', ['message' => __('employees.created_successfully'), 'type' => 'success']);
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

        return to_route('employees.show', $employee)
            ->with('flash', ['message' => __('employees.updated_successfully'), 'type' => 'success']);
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
