<?php

declare(strict_types=1);

namespace Modules\Entitlements\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ExternalApplication;
use App\Services\Integration\IntegrationAccessService;
use Illuminate\Http\Request;
use Modules\Entitlements\Http\Resources\EntitlementResource;

class EmployeeEntitlementController extends Controller
{
    public function __construct(private readonly IntegrationAccessService $access) {}

    public function __invoke(Request $request, Employee $employee)
    {
        // A person is held to the employee policy; a bound application to its
        // organization bound and to entitlements of its own provider only.
        if ($request->user() instanceof ExternalApplication) {
            $this->access->assertEmployee($request, $employee);
            $entitlements = $employee->entitlements()
                ->where(fn ($query) => $query->whereNull('service_provider_id')->orWhere('service_provider_id', $request->user()->service_provider_id))
                ->get();
        } else {
            $this->authorize('view', $employee);
            $entitlements = $employee->entitlements()->get();
        }

        return EntitlementResource::collection($entitlements);
    }
}
