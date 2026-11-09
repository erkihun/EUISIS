<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Backbone\Contracts\EmployeeDirectory;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Application\Services\TransportEligibilityService;

/**
 * Names the employee for a manual exception: exact employee number in,
 * id and name out, nothing else. Admin and portal share it; each route
 * carries its own manual-exception permission and a tight throttle.
 *
 * The answer is bounded so it cannot be used to read the register: it names
 * only employees the provider may carry (the transport eligibility the
 * boarding engine applies next). The portal uses the user's own provider;
 * the staff terminal sends the provider it is scanning for, and without one
 * staff are held to their organization scope. Anyone else is reported
 * exactly like an unknown number.
 */
class TransportManualLookupController extends Controller
{
    public function __invoke(
        Request $request,
        EmployeeDirectory $directory,
        ProviderPortalSession $portal,
        TransportEligibilityService $eligibility,
        OrganizationScopeService $scope,
    ): JsonResponse {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'min:3', 'max:40'],
            'provider_id' => ['nullable', 'uuid'],
        ]);

        $identity = $directory->findByNumber($data['employee_number']);
        $employee = $identity !== null && in_array($identity->status, [EmployeeStatus::Active->value, EmployeeStatus::Suspended->value], true)
            ? Employee::query()->with('currentAssignment')->find($identity->employeeUuid)
            : null;

        if ($employee === null || ! $this->mayLookUp($request, $employee, $data['provider_id'] ?? null, $portal, $eligibility, $scope)) {
            return response()->json(['message' => __('transport.reasons.employee_not_found')], 404);
        }

        return response()->json(['id' => $identity->employeeUuid, 'employee_number' => $identity->employeeNumber, 'full_name' => $identity->fullName]);
    }

    private function mayLookUp(Request $request, Employee $employee, ?string $providerId, ProviderPortalSession $portal, TransportEligibilityService $eligibility, OrganizationScopeService $scope): bool
    {
        if ($request->routeIs('provider.portal.*')) {
            $portalProviderId = $portal->providerId($request);

            return $portalProviderId !== null && $eligibility->mayCarry($employee, $portalProviderId);
        }

        if ($providerId !== null) {
            $provider = app(ProviderDirectory::class)->find($providerId);

            return $provider !== null && $provider->isActive() && $eligibility->mayCarry($employee, $provider->id);
        }

        $user = $request->user();

        return $user !== null && $scope->canAccessEmployee($user, $employee);
    }
}
