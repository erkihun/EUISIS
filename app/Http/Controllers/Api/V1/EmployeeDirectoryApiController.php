<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Backbone\Contracts\AssignmentContextReader;
use App\Http\Controllers\Api\V1\Concerns\FiltersOrganizationData;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Api\OrganizationDataPresenter;
use App\Services\Integration\IntegrationAccessService;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee reads for approved external applications.
 *
 * Strictly limited to workforce-structure facts: who holds which post, in which
 * unit, under which employment status. National id, phone, email, address,
 * salary, documents and photographs are never selected from the database here,
 * let alone serialised — see OrganizationDataPresenter::EMPLOYEE_COLUMNS.
 *
 * Every read is bounded to the caller's organizations (F2): a list only
 * contains employees whose current assignment lies inside the bound, and a
 * single employee outside it is a 404.
 */
class EmployeeDirectoryApiController extends Controller
{
    use FiltersOrganizationData;

    private const ASSIGNMENT_COLUMNS = 'currentAssignment:id,employee_id,organization_id,organization_unit_id,position_id,assignment_status,is_current,effective_from,effective_to';

    public function __construct(
        private readonly OrganizationDataPresenter $presenter,
        private readonly IntegrationAccessService $access,
        private readonly AssignmentContextReader $assignments,
    ) {}

    /** GET /api/v1/employees */
    public function index(Request $request): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'employees.basic_read');

        $query = Employee::query()
            ->select(OrganizationDataPresenter::EMPLOYEE_COLUMNS)
            ->with([self::ASSIGNMENT_COLUMNS]);

        $this->access->scopeEmployees($query, $request);
        $this->applyCodeFilter($query, $request, 'employee_number', 'employee_number');
        $this->applyStatusFilter($query, $request);
        $this->applyUpdatedAfter($query, $request);

        $organizationCode = $request->string('organization_code')->trim()->toString();
        $unitCode = $request->string('organization_unit_code')->trim()->toString();
        $positionCode = $request->string('position_code')->trim()->toString();

        // Structural filters all resolve through the current assignment, which
        // is what actually places an employee in the hierarchy.
        if ($organizationCode !== '' || $unitCode !== '' || $positionCode !== '') {
            $query->whereHas('currentAssignment', function ($assignment) use ($organizationCode, $unitCode, $positionCode): void {
                if ($organizationCode !== '') {
                    $assignment->whereHas('organization', fn ($organization) => $this->whereCodeEquals($organization, 'code', $organizationCode));
                }

                if ($unitCode !== '') {
                    $assignment->whereHas('organizationUnit', fn ($unit) => $this->whereCodeEquals($unit, 'code', $unitCode));
                }

                if ($positionCode !== '') {
                    $assignment->whereHas('position', fn ($position) => $this->whereCodeEquals($position, 'job_position_code', $positionCode));
                }
            });
        }

        $employees = $query->orderBy('employee_number')->paginate($this->perPage($request));

        return response()->json($this->paginated(
            $employees,
            $employees->getCollection()->map(fn (Employee $employee): array => $this->presenter->employee($employee))->all(),
        ));
    }

    /** GET /api/v1/employees/{employee} */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'employees.basic_read');
        $employee->load([self::ASSIGNMENT_COLUMNS]);
        $this->access->assertEmployee($request, $employee);

        return response()->json(['data' => $this->presenter->employee($employee)]);
    }

    /**
     * GET /api/v1/employees/{employee}/assignment
     *
     * The employee's current placement. 404 rather than a null body when there
     * is none, so a caller can tell "no such placement" from "placement with
     * empty fields".
     */
    public function assignment(Request $request, Employee $employee): JsonResponse
    {
        $this->access->assertHumanPermission($request, 'employee_assignments.read');

        // Read through the backbone contract (Core foundation Stage 1, first migrated read).
        $assignment = $this->assignments->currentFor((string) $employee->getKey());

        // Outside the caller's bound an employee is a 404 whether or not they have a placement.
        $this->access->assertOrganization($request, $assignment?->organizationUuid);

        if ($assignment === null) {
            return ApiError::response($request, 404, 'assignment_not_found', 'Not Found.');
        }

        return response()->json(['data' => $this->presenter->assignmentContext($assignment)]);
    }
}
