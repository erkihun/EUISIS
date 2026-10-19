<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersOrganizationData;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\Api\OrganizationDataPresenter;
use App\Services\Integration\IntegrationAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Organization-level reads for approved external applications.
 *
 * Every payload is built by OrganizationDataPresenter, so no sensitive employee
 * field can reach a response from here. Access is gated upstream by the
 * `api.external` gate (application status, IP allowlist, endpoint assignment,
 * rate limit, request log) and by `api.scope` per route; each read is then
 * bounded to the caller's organizations by IntegrationAccessService (F2).
 */
class OrganizationApiController extends Controller
{
    use FiltersOrganizationData;

    /** Depths the structure endpoint understands, shallowest first. */
    private const DEPTHS = ['unit', 'position', 'employee'];

    public function __construct(
        private readonly OrganizationDataPresenter $presenter,
        private readonly IntegrationAccessService $access,
    ) {}

    /** GET /api/v1/organizations */
    public function index(Request $request): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'organizations.read');

        $query = Organization::query()->with('type:id,code,name_en,name_am');

        $this->access->scopeQuery($query, $request, 'organizations.id');
        $this->applyCodeFilter($query, $request, 'organization_code', 'code');
        $this->applyStatusFilter($query, $request);
        $this->applyUpdatedAfter($query, $request);

        $organizations = $query->orderBy('name_en')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $organizations,
            $organizations->getCollection()
                ->map(fn (Organization $organization): array => $this->presenter->organization($organization))
                ->all(),
        ));
    }

    /** GET /api/v1/organizations/{organization} */
    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->bound($request, $organization, 'organizations.read');
        $organization->load('type:id,code,name_en,name_am');

        return response()->json(['data' => $this->presenter->organization($organization)]);
    }

    /** GET /api/v1/organizations/{organization}/units */
    public function units(Request $request, Organization $organization): JsonResponse
    {
        $this->bound($request, $organization, 'organization_units.read');
        $query = OrganizationUnit::query()->where('organization_id', $organization->getKey());

        $this->applyCodeFilter($query, $request, 'organization_unit_code', 'code');
        $this->applyStatusFilter($query, $request);
        $this->applyUpdatedAfter($query, $request);

        $units = $query->orderBy('sort_order')->orderBy('name_en')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $units,
            $units->getCollection()->map(fn (OrganizationUnit $unit): array => $this->presenter->unit($unit))->all(),
        ));
    }

    /** GET /api/v1/organizations/{organization}/positions */
    public function positions(Request $request, Organization $organization): JsonResponse
    {
        $this->bound($request, $organization, 'positions.read');
        $query = Position::query()
            ->where('organization_id', $organization->getKey())
            // Occupancy is part of the position payload; counting here keeps a
            // page of positions to one extra query instead of one per row.
            ->withCount(['assignments as current_assignments_count' => fn ($assignments) => $assignments
                ->where('is_current', true)]);

        $this->applyCodeFilter($query, $request, 'position_code', 'job_position_code');
        $this->applyUpdatedAfter($query, $request);
        $this->applyPositionStatusFilter($query, $request);

        $positions = $query->orderBy('title_en')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $positions,
            $positions->getCollection()->map(fn (Position $position): array => $this->presenter->position($position))->all(),
        ));
    }

    /** GET /api/v1/organizations/{organization}/employees */
    public function employees(Request $request, Organization $organization): JsonResponse
    {
        $this->bound($request, $organization, 'employees.basic_read');
        $employees = $this->employeeQuery($request)
            ->whereHas('currentAssignment', fn ($assignment) => $assignment
                ->where('organization_id', $organization->getKey()))
            ->orderBy('employee_number')
            ->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $employees,
            $employees->getCollection()->map(fn (Employee $employee): array => $this->presenter->employee($employee))->all(),
        ));
    }

    /**
     * GET /api/v1/organizations/{organization}/structure
     *
     * The nested view of the same data the flat endpoints expose:
     *   organization → units → positions → employee summary if occupied.
     *
     * `depth` decides how far down the tree the response goes, defaulting to
     * `position`. Employee depth is the expensive one, so it is opt-in rather
     * than the default.
     */
    public function structure(Request $request, Organization $organization): JsonResponse
    {
        $this->bound($request, $organization, 'organization_structure.read');
        $depth = $request->string('depth')->trim()->lower()->toString();

        if (! in_array($depth, self::DEPTHS, true)) {
            $depth = 'position';
        }

        // One nested document stays the contract for normal organizations.
        // Past api.structure_max_nodes positions (plus occupants at employee
        // depth) the document would run to megabytes for a large bureau, so
        // the caller is pointed at the paginated flat endpoints instead.
        // Units alone are always returned.
        if ($depth !== 'unit') {
            $nodes = Position::query()->where('organization_id', $organization->getKey())->count()
                + ($depth === 'employee'
                    ? EmployeeAssignment::query()->where('organization_id', $organization->getKey())->where('is_current', true)->count()
                    : 0);
            $limit = (int) config('api.structure_max_nodes', 10000);

            if ($nodes > $limit) {
                $base = "/api/v1/organizations/{$organization->getKey()}";

                return response()->json([
                    'message' => __('The structure is too large for one document; use the paginated endpoints.'),
                    'error_code' => 'structure_too_large',
                    'max_nodes' => $limit,
                    'nodes' => $nodes,
                    'paginated_endpoints' => ['units' => "{$base}/units", 'positions' => "{$base}/positions", 'employees' => "{$base}/employees"],
                ], 422);
            }
        }

        $organization->load('type:id,code,name_en,name_am');

        $units = OrganizationUnit::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        $positionsByUnit = collect();
        $employeesByPosition = collect();

        if ($depth !== 'unit') {
            $positions = Position::query()
                ->where('organization_id', $organization->getKey())
                ->withCount(['assignments as current_assignments_count' => fn ($assignments) => $assignments
                    ->where('is_current', true)])
                ->orderBy('title_en')
                ->get();

            $positionsByUnit = $positions->groupBy('organization_unit_id');

            if ($depth === 'employee') {
                // One query for every occupant in the organization, keyed by
                // position, rather than a lookup per position row.
                $employeesByPosition = Employee::query()
                    ->select(OrganizationDataPresenter::EMPLOYEE_COLUMNS)
                    ->with(['currentAssignment:id,employee_id,organization_id,organization_unit_id,position_id'])
                    ->whereHas('currentAssignment', fn ($assignment) => $assignment
                        ->where('organization_id', $organization->getKey()))
                    ->get()
                    ->groupBy(fn (Employee $employee): string => (string) $employee->currentAssignment?->position_id);
            }
        }

        return response()->json([
            'data' => [
                'organization' => $this->presenter->organization($organization),
                'depth' => $depth,
                'units' => $units->map(fn (OrganizationUnit $unit): array => $this->structureUnit(
                    $unit,
                    $depth,
                    $positionsByUnit->get($unit->getKey(), collect()),
                    $employeesByPosition,
                ))->all(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, Position>  $positions
     * @param  Collection<string, Collection<int, Employee>>  $employeesByPosition
     * @return array<string, mixed>
     */
    private function structureUnit(
        OrganizationUnit $unit,
        string $depth,
        Collection $positions,
        Collection $employeesByPosition,
    ): array {
        $payload = $this->presenter->unit($unit);

        if ($depth === 'unit') {
            return $payload;
        }

        $payload['positions'] = $positions->map(function (Position $position) use ($depth, $employeesByPosition): array {
            $entry = $this->presenter->position($position);

            if ($depth === 'employee') {
                // A position may legitimately hold more than one current
                // assignment, so the summary is a list rather than one object.
                $entry['employees'] = $employeesByPosition
                    ->get((string) $position->getKey(), collect())
                    ->map(fn (Employee $employee): array => $this->presenter->employeeSummary($employee))
                    ->values()
                    ->all();
            }

            return $entry;
        })->values()->all();

        return $payload;
    }

    /** Permission (person) and organization bound (both principals) for one organization. */
    private function bound(Request $request, Organization $organization, string $scope): void
    {
        $this->access->assertHumanPermission($request, $scope);
        $this->access->assertOrganization($request, (string) $organization->getKey());
    }

    /**
     * Base employee query: safe columns only, with the assignment needed to
     * report where the employee sits.
     */
    private function employeeQuery(Request $request): Builder
    {
        $query = Employee::query()
            ->select(OrganizationDataPresenter::EMPLOYEE_COLUMNS)
            ->with(['currentAssignment:id,employee_id,organization_id,organization_unit_id,position_id,assignment_status,is_current,effective_from,effective_to']);

        $this->applyCodeFilter($query, $request, 'employee_number', 'employee_number');
        $this->applyStatusFilter($query, $request);
        $this->applyUpdatedAfter($query, $request);

        return $query;
    }

    /**
     * Positions store availability as a boolean, so the shared status filter
     * (which compares a `status` column) does not apply.
     */
    private function applyPositionStatusFilter(Builder $query, Request $request): void
    {
        $status = $request->string('status')->trim()->lower()->toString();

        if ($status === 'active' || $status === 'inactive') {
            $query->where('is_active', $status === 'active');
        }
    }
}
