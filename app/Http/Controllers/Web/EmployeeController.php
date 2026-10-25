<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Employees\RegisterEmployeeAction;
use App\Actions\Employees\UpdateEmployeeAction;
use App\Actions\Positions\CreatePositionAction;
use App\Actions\Transfers\RequestEmployeeTransferAction;
use App\Enums\EmploymentType;
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
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\IdCards\IdCardQrCodeRenderer;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Positions\PositionCapacityService;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use App\Support\EmployeePhotoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EmployeeController extends Controller
{
    /** Most position options sent to one form/filter (one organization at a time). */
    private const OPTION_LIMIT = 1000;

    /**
     * Deepest registry page (50 per page = the first 10,000 matches). Paging
     * further is an OFFSET over the whole sorted register — 3.5 s at page
     * 30,000 of 3M employees — and nobody finds a person that way; the page
     * asks them to search or filter instead (production-readiness/database-capacity.md).
     */
    private const MAX_PAGE = 200;

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

        $selectedOrganization = null;
        $selectedPosition = null;
        $selectedOrganizationId = $request->string('organization_id')->toString()
            ?: ($isOrganizationScoped ? $organizations->first()?->id : null);
        $selectedOrganizationUnitId = $request->string('organization_unit_id')->toString() ?: null;
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

        if ($selectedPositionId !== null && $selectedPosition === null) {
            $selectedPositionQuery = Position::query()
                ->where('is_active', true)
                ->whereKey($selectedPositionId);
            $organizationScopeService->applyOrganizationScope($selectedPositionQuery, $user);
            $selectedPosition = $selectedPositionQuery->first([
                'id', 'job_position_code', 'title_en', 'title_am', 'organization_id', 'organization_unit_id',
            ]);

            if ($selectedPosition === null) {
                abort(403);
            }
        }

        if ($selectedOrganizationUnitId !== null) {
            $unitOrganizationId = OrganizationUnit::query()
                ->whereKey($selectedOrganizationUnitId)
                ->value('organization_id');

            if ($unitOrganizationId === null || ! $organizationScopeService->canAccess($user, $unitOrganizationId)) {
                abort(403);
            }
        }

        $organizationUnitOptionsQuery = OrganizationUnit::query()
            ->where('status', OrganizationUnitStatus::Active->value)
            ->whereNull('deleted_at')
            ->when($selectedOrganizationId !== null, fn ($query) => $query->where('organization_id', $selectedOrganizationId))
            ->orderBy('name_en');
        $organizationScopeService->applyOrganizationScope($organizationUnitOptionsQuery, $user);

        $positionOptionsQuery = Position::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->when($selectedOrganizationId !== null, fn ($query) => $query->where('organization_id', $selectedOrganizationId))
            ->when($selectedOrganizationUnitId !== null, fn ($query) => $query->where('organization_unit_id', $selectedOrganizationUnitId))
            ->orderBy('title_en');
        $organizationScopeService->applyOrganizationScope($positionOptionsQuery, $user);

        $employmentType = $request->string('employment_type')->toString();
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        /*
         * Sorting is whitelisted rather than passed through: the column name
         * reaches orderBy() directly, and only these five are indexed columns
         * on `employees` that make sense to order a registry by.
         */
        $sortableColumns = ['full_name', 'employee_number', 'employment_type', 'status', 'created_at'];
        $sort = $request->string('sort')->toString();
        $sort = in_array($sort, $sortableColumns, true) ? $sort : 'full_name';
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

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
                $selectedOrganizationUnitId !== null,
                fn ($query) => $query->whereHas('currentAssignment', fn ($assignmentQuery) => $assignmentQuery->where('organization_unit_id', $selectedOrganizationUnitId))
            )
            ->when(
                $selectedPosition !== null,
                fn ($query) => $query->whereHas('currentAssignment', fn ($assignmentQuery) => $assignmentQuery->where('position_id', $selectedPosition->id))
            )
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $this->applyRegistrySearch($nested, $search)))
            ->when(in_array($employmentType, EmploymentType::values(), true), fn ($query) => $query->where('employment_type', $employmentType))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(50, ['*'], 'page', min(self::MAX_PAGE, max(1, $request->integer('page', 1))))
            ->withQueryString();

        return Inertia::render('Employees/Index', [
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
            'organizations' => $organizations->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
            ])->values(),
            // Filter options exist only for a chosen organization (the page
            // reloads on change), so a citywide view never ships every unit.
            'organizationUnits' => ($selectedOrganizationId === null ? collect() : $organizationUnitOptionsQuery
                ->get(['id', 'organization_id', 'code', 'name_en', 'name_am']))
                ->map(fn (OrganizationUnit $unit): array => [
                    'id' => $unit->id,
                    'organization_id' => $unit->organization_id,
                    'code' => $unit->code,
                    'name_en' => $unit->name_en,
                    'name_am' => $unit->name_am,
                ]),
            'positions' => ($selectedOrganizationId === null ? collect() : $positionOptionsQuery
                ->limit(self::OPTION_LIMIT)
                ->get(['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'title_en', 'title_am']))
                ->map(fn (Position $position): array => [
                    'id' => $position->id,
                    'organization_id' => $position->organization_id,
                    'organization_unit_id' => $position->organization_unit_id,
                    'job_position_code' => $position->job_position_code,
                    'title_en' => $position->title_en,
                    'title_am' => $position->title_am,
                ]),
            'showOrganizationColumn' => $organizations->count() > 1,
            'employees' => EmployeeResource::collection($employeesPaginated->getCollection())->resolve(),
            'employees_pagination' => [
                'current_page' => $employeesPaginated->currentPage(),
                'last_page' => min(self::MAX_PAGE, $employeesPaginated->lastPage()),
                'per_page' => $employeesPaginated->perPage(),
                'total' => $employeesPaginated->total(),
                'page_limit_reached' => $employeesPaginated->lastPage() > self::MAX_PAGE,
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'organization_id' => $selectedOrganizationId ?? '',
                'organization_unit_id' => $selectedOrganizationUnitId ?? '',
                'position_id' => $selectedPositionId ?? '',
                'employment_type' => in_array($employmentType, EmploymentType::values(), true) ? $employmentType : '',
            ],
            'sort' => [
                'column' => $sort,
                'direction' => $direction,
            ],
            'can' => [
                'create' => $user?->can('create', Employee::class) ?? false,
                // List rows are already organization-scoped. The edit endpoint
                // continues to enforce the per-employee update policy.
                'update' => $user?->can('employees.manage') ?? false,
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

        // Positions with a free slot: approved establishment slots, or one
        // person when there is no establishment (CON-5).
        $selectedPositionQuery = app(PositionCapacityService::class)->whereHasFreeSlot(Position::query()->where('is_active', true))
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

        $availableOrganizations = $organizationQuery->get(['id', 'name_en', 'name_am']);

        if ($selectedOrganizationId === null && $availableOrganizations->count() === 1) {
            $selectedOrganizationId = $availableOrganizations->first()?->id;
        }

        $organizationLocked = $selectedOrganizationId !== null && $availableOrganizations->count() === 1;

        $organizationUnitQuery = OrganizationUnit::query()
            ->where('status', OrganizationUnitStatus::Active->value)
            ->whereNull('deleted_at')
            ->orderBy('name_en');

        $positionQuery = app(PositionCapacityService::class)->whereHasFreeSlot(Position::query()
            ->where('is_active', true)
            ->whereNull('deleted_at'))
            ->orderBy('title_en');

        $organizationScopeService->applyOrganizationScope($organizationUnitQuery, $user);
        $organizationScopeService->applyOrganizationScope($positionQuery, $user);

        // Options for one organization only — the selected one, or the form's
        // default (the first available). The form reloads them on change.
        $optionsOrganizationId = $selectedOrganizationId ?? $availableOrganizations->first()?->id;
        $organizationUnitQuery->where('organization_id', $optionsOrganizationId);
        $positionQuery->where('organization_id', $optionsOrganizationId)->limit(self::OPTION_LIMIT);

        return Inertia::render('Employees/Create', [
            'organizations' => $availableOrganizations,
            'organizationUnits' => $organizationUnitQuery
                ->get(['id', 'organization_id', 'name_en', 'name_am', 'code']),
            'hierarchyVersions' => HierarchyVersion::query()->orderByDesc('effective_from')->get(['id', 'version_name', 'status']),
            'positions' => $positionQuery
                ->get(['id', 'job_position_code', 'title_en', 'title_am', 'organization_id', 'organization_unit_id']),
            'selectedOrganizationId' => $selectedOrganizationId,
            'selectedOrganizationUnitId' => $selectedOrganizationUnitId,
            'selectedPositionId' => $selectedPositionId,
            'organizationLocked' => $organizationLocked,
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
     * Registry search, matched to what was typed so every branch is served by
     * an index at 3M employees (docs/production-readiness/database-capacity.md):
     *
     *  - an e-mail address (contains "@"): e-mail, substring (trigram index);
     *  - a number or code (a digit and no space) or a phone number: employee
     *    number by prefix (lower(employee_number) pattern index), and for a
     *    phone number the phone, substring (trigram);
     *  - anything else, a name: Amharic full_name and English name_en,
     *    substring (trigram indexes), plus the employee-number prefix for a
     *    single word.
     *
     * Before, every search OR-ed all five columns with '%term%'; e-mail and
     * phone had no trigram index, so each search read all 3M rows (2.7 s).
     * LIKE wildcards typed by the user are literal ('!' escapes them on every engine).
     */
    private function applyRegistrySearch($query, string $search): void
    {
        $like = ci_like_operator();
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $contains = fn ($q, string $column) => $q->orWhereRaw("{$column} {$like} ? ESCAPE '!'", ["%{$term}%"]);

        if (str_contains($search, '@')) {
            $contains($query, 'email');

            return;
        }

        $phoneLike = preg_match('/^[+\d][\d\s().\-]{2,}$/', $search) === 1;
        if ($phoneLike || (preg_match('/\d/', $search) === 1 && ! str_contains($search, ' '))) {
            $query->orWhereRaw("lower(employee_number) like lower(?) ESCAPE '!'", ["{$term}%"]);
            if ($phoneLike) {
                $contains($query, 'phone');
            }

            return;
        }

        $contains($query, 'full_name');
        $contains($query, 'name_en');
        // One word may also be the start of a code ("REG-"): cheap via the prefix index.
        if (! str_contains($search, ' ')) {
            $query->orWhereRaw("lower(employee_number) like lower(?) ESCAPE '!'", ["{$term}%"]);
        }
    }

    /**
     * Whether the position has no free slot: its current, active assignments
     * fill its approved establishment slots (one person without an
     * establishment). The same rule as the create-page query and
     * EmployeeStoreRequest (PositionCapacityService, CON-5).
     */
    private function positionIsOccupied(string $positionId): bool
    {
        return ! app(PositionCapacityService::class)->hasFreeSlot($positionId);
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

        // "occupied" means no free slot under the capacity rule (CON-5).
        [$fullSql, $fullBindings] = app(PositionCapacityService::class)->fullExpression();
        $positionQuery = Position::query()
            ->whereNull('deleted_at')
            ->select(['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'title_en', 'title_am', 'is_active'])
            ->selectRaw("{$fullSql} as is_occupied", $fullBindings)
            ->orderBy('title_en');
        $organizationScopeService->applyOrganizationScope($positionQuery, $user);
        $positionsByUnit = $positionQuery
            ->get()
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

        // The edit form never used a position list (placement changes go
        // through transfers), so none is sent.
        return Inertia::render('Employees/Edit', [
            'employee' => (new EmployeeDetailResource($employee))->resolve(),
        ]);
    }

    public function show(
        Request $request,
        Employee $employee,
        OrganizationScopeService $organizationScopeService,
        EmployeeFeedbackTokenService $feedbackTokenService,
        IdCardQrCodeRenderer $qrRenderer,
    ): Response {
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
            'activeIdCard',
        ]);

        return Inertia::render('Employees/Show', [
            'employee' => (new EmployeeDetailResource($employee))->resolve(),
            'organizations' => Organization::query()
                ->whereIn('id', $organizationScopeService->accessibleOrganizationIds(request()->user()))
                ->orderBy('name_en')
                ->get(['id', 'name_en']),
            'qrCodes' => $this->qrCodes($request, $employee, $feedbackTokenService, $qrRenderer),
        ]);
    }

    /**
     * The two public QR codes for an employee, each gated on its own permission.
     *
     * Both payloads are a URL and nothing else — no name, contact detail,
     * national ID or organization data travels inside the code, so a
     * photographed QR reveals no more than the page it opens.
     *
     * Rendering this section never rotates a token. The feedback token is
     * provisioned only when one is genuinely missing for an active employee
     * (see EmployeeFeedbackTokenService::ensureActiveTokenFor), which is a
     * create-if-absent, never a regenerate. A printed QR therefore survives
     * every ordinary edit to the employee record.
     *
     * @return array<string, mixed>
     */
    private function qrCodes(
        Request $request,
        Employee $employee,
        EmployeeFeedbackTokenService $feedbackTokenService,
        IdCardQrCodeRenderer $qrRenderer,
    ): array {
        $user = $request->user();

        $canViewIdQr = $user?->can('cards.view') ?? false;
        $canManageFeedbackQr = $user?->can('service_feedback.settings.manage') ?? false;

        $idCard = null;

        if ($canViewIdQr && $employee->activeIdCard?->public_card_uuid !== null) {
            $url = route('id-checker.show', $employee->activeIdCard->public_card_uuid);

            $idCard = [
                'url' => $url,
                'qr_svg' => $qrRenderer->asSvgDataUri($url, 200),
                'card_number' => $employee->activeIdCard->card_number,
            ];
        }

        $feedback = null;

        if ($canManageFeedbackQr) {
            $token = $feedbackTokenService->ensureActiveTokenFor($employee, $user, $request);

            if ($token !== null) {
                $feedback = [
                    'url' => $token->publicUrl(),
                    'qr_svg' => $qrRenderer->asSvgDataUri($token->publicUrl(), 200),
                    'status' => $token->status->value,
                ];
            }
        }

        return [
            'canViewIdQr' => $canViewIdQr,
            'canManageFeedbackQr' => $canManageFeedbackQr,
            'idCard' => $idCard,
            'feedback' => $feedback,
        ];
    }

    public function store(
        EmployeeStoreRequest $request,
        RegisterEmployeeAction $registerEmployeeAction,
        CreatePositionAction $createPositionAction,
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
            // Naming a position by title may create one, so it needs the position
            // permission and goes through the Core action (code rules, owner/host
            // checks, audit); only an active position is reused.
            $this->authorize('create', Position::class);

            $position = Position::query()->where([
                'organization_id' => $request->string('organization_id')->toString(),
                'organization_unit_id' => $organizationUnitId,
                'title_en' => $request->string('position_title')->toString(),
                'is_active' => true,
            ])->first();

            $position ??= $createPositionAction->execute([
                'organization_id' => $request->string('organization_id')->toString(),
                'organization_unit_id' => $organizationUnitId,
                'title_en' => $request->string('position_title')->toString(),
                'is_active' => true,
                'effective_from' => now()->toDateString(),
            ], $request->user());

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
            'nationality',
            'employment_type',
            'address',
            'emergency_contact_name',
            'emergency_contact_phone',
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
            // Private disk: photos are personal data (SEC-013).
            $employee->update(['photo_path' => EmployeePhotoStorage::store($request->file('photo'))]);
        }

        return to_route('employees.show', $employee)
            ->with('flash', ['message' => __('employees.created_successfully'), 'type' => 'success']);
    }

    public function update(
        EmployeeUpdateRequest $request,
        Employee $employee,
        UpdateEmployeeAction $updateEmployeeAction,
    ): RedirectResponse {
        $this->authorize('update', $employee);

        $attributes = $request->safe()->except(['photo', 'remove_photo']);
        $attributes['full_name'] = trim(implode(' ', array_filter([
            $request->string('first_name')->toString(),
            $request->string('middle_name')->toString(),
            $request->string('last_name')->toString(),
        ])));

        $previousPhoto = $employee->photo_path;
        $newPhoto = null;
        if ($request->boolean('remove_photo') && $previousPhoto) {
            $attributes['photo_path'] = null;
        }

        if ($request->hasFile('photo')) {
            // Private disk: photos are personal data (SEC-013).
            $newPhoto = EmployeePhotoStorage::store($request->file('photo'));
            $attributes['photo_path'] = $newPhoto;
        }

        try {
            // Core action: row lock, status transition (ADR 0002) and audit in one transaction.
            $updateEmployeeAction->execute($employee, $attributes, $request->user(), $request);
        } catch (Throwable $exception) {
            // Nothing was saved: keep the old photo and drop the new upload.
            if ($newPhoto !== null) {
                EmployeePhotoStorage::delete($newPhoto);
            }

            throw $exception;
        }

        if ($previousPhoto && ($newPhoto !== null || $request->boolean('remove_photo'))) {
            EmployeePhotoStorage::delete($previousPhoto);
        }

        return to_route('employees.show', $employee)
            ->with('flash', ['message' => __('employees.updated_successfully'), 'type' => 'success']);
    }

    public function transfer(
        EmployeeTransferRequest $request,
        Employee $employee,
        RequestEmployeeTransferAction $requestEmployeeTransferAction,
        OrganizationScopeService $organizationScopeService,
    ): RedirectResponse {
        $this->authorize('transfer', $employee);
        abort_unless($organizationScopeService->canAccessOrganization($request->user(), $request->string('organization_id')->toString()), 403);

        $transfer = $requestEmployeeTransferAction->execute(
            $employee,
            $request->string('organization_id')->toString(),
            $request->user(),
            $request->input('reason'),
            $request->input('effective_date'),
            $request->input('position_id'),
        );

        return to_route('transfers.dashboard')
            ->with('flash', ['message' => __('Transfer draft created.'), 'type' => 'success']);
    }
}
