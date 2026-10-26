<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/** Employees (ADR 0009): permission, the employee's organization, and the record's stage. */
final class EmployeePolicy
{
    use ChecksOrganizationScope;

    public function view(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'employees.view', $this->employeeOrganization($employee));
    }

    /** Details change only while a draft or once approved: what was submitted is what is verified. */
    public function update(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'employees.update', $this->employeeOrganization($employee),
            ! in_array($employee->status, [Employee::SUBMITTED, Employee::VERIFIED], true));
    }

    public function changeStatus(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'employees.manage', $this->employeeOrganization($employee), $employee->isApproved());
    }

    public function submit(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'employees.submit', $this->employeeOrganization($employee), $employee->status === Employee::DRAFT);
    }

    public function verify(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'employees.verify', $this->employeeOrganization($employee),
            $employee->status === Employee::SUBMITTED && (int) $employee->submitted_by !== (int) $user->getKey());
    }

    public function return(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'employees.return', $this->employeeOrganization($employee),
            in_array($employee->status, [Employee::SUBMITTED, Employee::VERIFIED], true));
    }

    public function approve(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'employees.approve', $this->employeeOrganization($employee), $employee->status === Employee::VERIFIED
            && ! in_array((int) $user->getKey(), array_map('intval', array_filter([$employee->registered_by, $employee->submitted_by, $employee->verified_by])), true));
    }

    public function requestCard(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'card-requests.create', $this->employeeOrganization($employee), $employee->isApproved());
    }

    public function transfer(User $user, Employee $employee): bool
    {
        return $this->permits($user, 'assignments.change', $this->employeeOrganization($employee), $employee->isApproved() && ! $employee->isSeparated());
    }
}
