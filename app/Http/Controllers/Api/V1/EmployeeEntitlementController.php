<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntitlementResource;
use App\Models\Employee;

class EmployeeEntitlementController extends Controller
{
    public function __invoke(Employee $employee)
    {
        $this->authorize('view', $employee);

        return EntitlementResource::collection($employee->entitlements()->get());
    }
}
