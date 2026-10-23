<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Names the employee for a manual exception: exact employee number in,
 * id and name out, nothing else. Admin and portal share it; each route
 * carries its own manual-exception permission and a tight throttle, so it
 * cannot be used to enumerate the register.
 */
class TransportManualLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_number' => ['required', 'string', 'min:3', 'max:40']]);

        $employee = Employee::query()
            ->where('employee_number', trim($data['employee_number']))
            ->whereIn('status', [EmployeeStatus::Active->value, EmployeeStatus::Suspended->value])
            ->first(['id', 'employee_number', 'full_name']);

        if ($employee === null) {
            return response()->json(['message' => __('transport.reasons.employee_not_found')], 404);
        }

        return response()->json(['id' => $employee->id, 'employee_number' => $employee->employee_number, 'full_name' => $employee->full_name]);
    }
}
