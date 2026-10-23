<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Data\EmployeeIdentity;
use App\Models\Employee;
use Illuminate\Support\Str;

final class EloquentEmployeeDirectory implements EmployeeDirectory
{
    private const COLUMNS = ['id', 'employee_number', 'full_name', 'name_en', 'status', 'current_assignment_id'];

    public function findByUuid(string $employeeUuid): ?EmployeeIdentity
    {
        // A malformed id is "not found", never a uuid cast error on PostgreSQL.
        if (! Str::isUuid($employeeUuid)) {
            return null;
        }

        $employee = Employee::query()->whereKey($employeeUuid)->first(self::COLUMNS);

        return $employee === null ? null : EmployeeIdentity::fromModel($employee);
    }

    public function findByNumber(string $employeeNumber): ?EmployeeIdentity
    {
        $employee = Employee::query()->where('employee_number', trim($employeeNumber))->first(self::COLUMNS);

        return $employee === null ? null : EmployeeIdentity::fromModel($employee);
    }
}
