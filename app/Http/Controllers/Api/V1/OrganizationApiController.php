<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersOrganizationData;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\Api\OrganizationDataPresenter;
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
 * rate limit, request log) and by `api.scope` per route.
 */
class OrganizationApiController extends Controller
{
    use FiltersOrganizationData;

    /** Depths the structure endpoint understands, shallowest first. */
    private const DEPTHS = ['unit', 'position', 'employee'];

    public function __construct(private readonly OrganizationDataPresenter $presenter) {}

    /** GET /api/v1/organizations */
    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()->with('type:id,code,name_en,name_am');

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
    public function show(Organization $organization): JsonResponse
    {
        $organization->load('type:id,code,name_en,name_am');

        return response()->json(['data' => $this->presenter->organization($organization)]);
    }

    /** GET /api/v1/organizations/{organization}/units */
    public function units(Request $request, Organization $organization): JsonResponse
    {
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
        $depth = $request->string('depth')->trim()->lower()->toString();

        if (! in_array($depth, self::DEPTHS, true)) {
            $depth = 'position';
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
