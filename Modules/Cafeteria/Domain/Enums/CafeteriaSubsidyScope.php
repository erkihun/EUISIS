<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Domain\Enums;

enum CafeteriaSubsidyScope: string
{
    case AllEmployees = 'all_employees';
    case Organization = 'organization';
    case EmployeeType = 'employee_type';
    case SelectedEmployees = 'selected_employees';

    public function label(): string
    {
        return (string) __('enums.cafeteria_subsidy_scope.'.$this->value);
    }
}
