<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Backbone\Contracts\EmployeeDirectory;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Transport\TransportEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Names the employee for a manual exception: exact employee number in,
 * id and name out, nothing else. Admin and portal share it; each route
 * carries its own manual-exception permission and a tight throttle.
 *
 * The answer is bounded so it cannot be used to read the register: a
 * provider user finds only employees that provider may carry (the same
 * transport eligibility the boarding engine applies next), and staff find
 * only employees inside their organization scope. Anyone else is reported
 * exactly like an unknown number.
 */
class TransportManualLookupController extends Controller
{
    public function __invoke(
        Request $request,
        EmployeeDirectory $directory,
        ProviderPortalContext $portal,
        TransportEligibilityService $eligibility,
        OrganizationScopeService $scope,
    ): JsonResponse {
        $data = $request->validate(['employee_number' => ['required', 'string', 'min:3', 'max:40']]);

        $identity = $directory->findByNumber($data['employee_number']);
        $employee = $identity !== null && in_array($identity->status, [EmployeeStatus::Active->value, EmployeeStatus::Suspended->value], true)
            ? Employee::query()->with('currentAssignment')->find($identity->employeeUuid)
            : null;

        if ($employee === null || ! $this->mayLookUp($request, $employee, $portal, $eligibility, $scope)) {
            return response()->json(['message' => __('transport.reasons.employee_not_found')], 404);
        }

        return response()->json(['id' => $identity->employeeUuid, 'employee_number' => $identity->employeeNumber, 'full_name' => $identity->fullName]);
    }

    private function mayLookUp(Request $request, Employee $employee, ProviderPortalContext $portal, TransportEligibilityService $eligibility, OrganizationScopeService $scope): bool
    {
        if ($request->routeIs('provider.portal.*')) {
            $provider = $portal->provider($request);

            return $provider !== null && $eligibility->check($employee, null, $provider)['eligible'];
        }

        $user = $request->user();

        return $user !== null && $scope->canAccessEmployee($user, $employee);
    }
}
