<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\EmployeeDuplicateFlag;

class DetectDuplicateEmployeeAction
{
    public function execute(Employee $employee): ?EmployeeDuplicateFlag
    {
        $match = Employee::query()
            ->whereKeyNot($employee->getKey())
            ->where(function ($query) use ($employee): void {
                $query->where('employee_number', $employee->employee_number);

                if ($employee->phone !== null) {
                    $query->orWhere('phone', $employee->phone);
                }

                if ($employee->date_of_birth !== null) {
                    $query->orWhere(function ($nested) use ($employee): void {
                        $nested
                            ->where('full_name', $employee->full_name)
                            ->whereDate('date_of_birth', $employee->date_of_birth);
                    });
                }
            })
            ->first();

        if ($match === null) {
            return null;
        }

        $matchedFields = [];

        if ($match->employee_number === $employee->employee_number) {
            $matchedFields[] = 'employee_number';
        }

        if ($employee->phone !== null && $match->phone === $employee->phone) {
            $matchedFields[] = 'phone';
        }

        if (
            $employee->date_of_birth !== null
            && $match->full_name === $employee->full_name
            && $match->date_of_birth?->toDateString() === $employee->date_of_birth?->toDateString()
        ) {
            $matchedFields[] = 'full_name/date_of_birth';
        }

        return EmployeeDuplicateFlag::query()->create([
            'employee_id' => $employee->id,
            'matched_employee_id' => $match->id,
            'risk_score' => 90,
            'matched_fields' => $matchedFields,
            'status' => 'flagged',
        ]);
    }
}
