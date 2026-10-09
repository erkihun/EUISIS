<?php

declare(strict_types=1);

namespace App\Enums;

enum CafeteriaSubsidyScope: string
{
    case AllEmployees     = 'all_employees';
    case Organization     = 'organization';
    case EmployeeType     = 'employee_type';
    case SelectedEmployees = 'selected_employees';

    public function label(): string
    {
        return match($this) {
            self::AllEmployees     => 'All Employees',
            self::Organization     => 'Organization',
            self::EmployeeType     => 'Employee Type',
            self::SelectedEmployees => 'Selected Employees',
        };
    }
}
