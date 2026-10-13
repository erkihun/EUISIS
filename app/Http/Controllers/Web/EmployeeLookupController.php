<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scoped, server-side employee search for admin pickers. Pages use this
 * instead of shipping an employee list as a page prop: at most 20 rows, a
 * minimum of two characters, organization scope applied, and no contact or
 * personal data in the response.
 */
class EmployeeLookupController extends Controller
{
    private const LIMIT = 20;

    /** Any one of these lets a user search employees within their scope. */
    public const PERMISSIONS = [
        'employees.viewAny',
        'employees.view',
        'entitlements.manage',
        'cafeteria_employee_exclusions.create',
        'cafeteria_employee_exclusions.view',
        'cafeteria_ledger.view',
        'id-cards.submitRequest',
        'cards.manage',
    ];

    public function __invoke(Request $request, OrganizationScopeService $scope): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(self::PERMISSIONS), 403);

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $term = mb_substr($term, 0, 60);
        $like = ci_like_operator();
        $contains = '%'.addcslashes($term, '%_\\').'%';
        $prefix = addcslashes($term, '%_\\').'%';

        $query = Employee::query()
            ->where('status', 'active')
            ->where(fn (Builder $q) => $q
                ->where('employee_number', $like, $prefix)
                ->orWhere('full_name', $like, $contains)
                ->orWhere('name_en', $like, $contains));

        if (! $scope->isUnrestricted($user)) {
            $query->whereIn('current_assignment_id', EmployeeAssignment::query()
                ->where('is_current', true)
                ->whereIn('organization_id', $scope->allowedOrganizationIds($user))
                ->select('id'));
        }

        $rows = $query
            ->with('currentAssignment.organization:id,name_en,name_am')
            ->orderBy('full_name')
            ->limit(self::LIMIT)
            ->get(['id', 'employee_number', 'full_name', 'name_en', 'current_assignment_id']);

        return response()->json(['data' => $rows->map(fn (Employee $employee): array => [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'name_en' => $employee->name_en,
            'organization' => $employee->currentAssignment?->organization?->only(['name_en', 'name_am']),
        ])->values()]);
    }
}
