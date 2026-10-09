<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersOrganizationData;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\Api\OrganizationDataPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization-unit reads for approved external applications.
 *
 * Payloads come from OrganizationDataPresenter; nothing sensitive is exposed.
 */
class OrganizationUnitApiController extends Controller
{
    use FiltersOrganizationData;

    public function __construct(private readonly OrganizationDataPresenter $presenter) {}

    /** GET /api/v1/organization-units */
    public function index(Request $request): JsonResponse
    {
        $query = OrganizationUnit::query();

        $this->applyCodeFilter($query, $request, 'organization_unit_code', 'code');
        $this->applyStatusFilter($query, $request);
        $this->applyUpdatedAfter($query, $request);

        // Filtering units by their organization's code, which is the handle an
        // external system holds, rather than requiring our organization UUID.
        $organizationCode = $request->string('organization_code')->trim()->toString();

        if ($organizationCode !== '') {
            $query->whereHas('organization', fn ($organization) => $organization
                ->where('code', ci_like_operator(), $organizationCode));
        }

        $units = $query->orderBy('sort_order')->orderBy('name_en')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $units,
            $units->getCollection()->map(fn (OrganizationUnit $unit): array => $this->presenter->unit($unit))->all(),
        ));
    }

    /** GET /api/v1/organization-units/{unit} */
    public function show(OrganizationUnit $unit): JsonResponse
    {
        return response()->json(['data' => $this->presenter->unit($unit)]);
    }

    /** GET /api/v1/organization-units/{unit}/positions */
    public function positions(Request $request, OrganizationUnit $unit): JsonResponse
    {
        $query = Position::query()
            ->where('organization_unit_id', $unit->getKey())
            ->withCount(['assignments as current_assignments_count' => fn ($assignments) => $assignments
                ->where('is_current', true)]);

        $this->applyCodeFilter($query, $request, 'position_code', 'job_position_code');
        $this->applyUpdatedAfter($query, $request);

        $status = $request->string('status')->trim()->lower()->toString();

        if ($status === 'active' || $status === 'inactive') {
            $query->where('is_active', $status === 'active');
        }

        $positions = $query->orderBy('title_en')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $positions,
            $positions->getCollection()->map(fn (Position $position): array => $this->presenter->position($position))->all(),
        ));
    }

    /** GET /api/v1/organization-units/{unit}/employees */
    public function employees(Request $request, OrganizationUnit $unit): JsonResponse
    {
        $query = Employee::query()
            ->select(OrganizationDataPresenter::EMPLOYEE_COLUMNS)
            ->with(['currentAssignment:id,employee_id,organization_id,organization_unit_id,position_id,assignment_status,is_current,effective_from,effective_to'])
            ->whereHas('currentAssignment', fn ($assignment) => $assignment
                ->where('organization_unit_id', $unit->getKey()));

        $this->applyCodeFilter($query, $request, 'employee_number', 'employee_number');
        $this->applyStatusFilter($query, $request);
        $this->applyUpdatedAfter($query, $request);

        $employees = $query->orderBy('employee_number')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $employees,
            $employees->getCollection()->map(fn (Employee $employee): array => $this->presenter->employee($employee))->all(),
        ));
    }
}
