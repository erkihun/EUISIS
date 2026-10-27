<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Employee;
use App\Models\User;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;

/**
 * Permission alone is not access, nor is scope alone (ADR 0009): every policy
 * decision is the permission, the record's organization inside the actor's
 * explicit scope (OrganizationScope, which fails closed), and the record's
 * state. An inactive account or one without a scope is refused everything.
 */
trait ChecksOrganizationScope
{
    protected function permits(User $user, string $permission, ?string $organizationId, bool $state = true): bool
    {
        return $state
            && $user->is_active
            && $user->hasOrganizationScope()
            && $user->can($permission)
            && app(OrganizationScope::class)->allows($user, $organizationId);
    }

    /** The organization an employee is placed in now (their pending first placement while not yet approved). */
    protected function employeeOrganization(Employee|string $employee): ?string
    {
        $id = $employee instanceof Employee ? $employee->getKey() : $employee;
        if ($employee instanceof Employee && $employee->relationLoaded('currentAssignment')) {
            $organizationId = $employee->currentAssignment?->organization_id;
        } else {
            $organizationId = DB::table('employees')->join('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id')
                ->where('employees.id', $id)->value('employee_assignments.organization_id');
        }

        return $organizationId === null ? null : (string) $organizationId;
    }
}
