<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersOrganizationData;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Services\Api\OrganizationDataPresenter;
use App\Services\Integration\IntegrationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assignment reads for approved external applications (master backbone,
 * Stage 4). An assignment is the employment context: employee, organization,
 * unit, position and dates. Every row is bounded to the caller's
 * organizations by the assignment's own organization, so a transferred
 * employee's earlier placements elsewhere are not disclosed.
 */
class AssignmentApiController extends Controller
{
    use FiltersOrganizationData;

    private const COLUMNS = ['id', 'employee_id', 'organization_id', 'organization_unit_id', 'position_id', 'assignment_status', 'is_current', 'effective_from', 'effective_to', 'updated_at'];

    public function __construct(
        private readonly OrganizationDataPresenter $presenter,
        private readonly IntegrationAccessService $access,
    ) {}

    /**
     * GET /api/v1/assignments
     *
     * Current assignments by default; `current=false` includes closed ones.
     * Filters: employee_id, organization_id, position_id, updated_after.
     */
    public function index(Request $request): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'employee_assignments.read');
        $filters = $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'organization_id' => ['nullable', 'uuid'],
            'position_id' => ['nullable', 'uuid'],
        ]);

        $query = EmployeeAssignment::query()->select(self::COLUMNS);
        $this->access->scopeQuery($query, $request);
        foreach ($filters as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }
        if ($request->boolean('current', true)) {
            $query->where('is_current', true);
        }
        $this->applyUpdatedAfter($query, $request);

        $page = $query->orderBy('employee_id')->orderBy('effective_from')->orderBy('id')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $page,
            $page->getCollection()->map(fn (EmployeeAssignment $assignment): array => $this->presenter->assignment($assignment))->all(),
        ));
    }

    /**
     * GET /api/v1/employees/{employee}/assignments
     *
     * The employee's placements, newest first: the employee must be inside the
     * caller's bound, and so must each placement listed.
     */
    public function history(Request $request, Employee $employee): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'employee_assignments.read');
        $this->access->assertEmployee($request, $employee);

        $query = EmployeeAssignment::query()->select(self::COLUMNS)->where('employee_id', $employee->getKey());
        $this->access->scopeQuery($query, $request);
        $page = $query->orderByDesc('effective_from')->orderByDesc('id')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $page,
            $page->getCollection()->map(fn (EmployeeAssignment $assignment): array => $this->presenter->assignment($assignment))->all(),
        ));
    }
}
