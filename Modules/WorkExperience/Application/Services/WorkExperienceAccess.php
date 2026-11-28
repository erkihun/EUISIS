<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Application\Services;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Modules\WorkExperience\Domain\Models\WorkExperienceRequest;

/**
 * Who may see and do what with work experience (ADR 0040).
 *
 * A permission is never enough on its own: an employee reads their own record
 * and asks for their own certificate; an officer works inside their
 * organization scope, which fails closed (ADR 0003). Nobody records their own
 * experience or decides their own request.
 *
 * An employee who has left may have no current assignment. Their office is
 * then the institution of their last assignment, so the office they left can
 * still write their certificate.
 */
class WorkExperienceAccess
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function employeeOf(User $user): ?Employee
    {
        $employee = $user->employee;

        return $employee instanceof Employee ? $employee : null;
    }

    /** The institution that keeps this employee's record: the current assignment's, or the last one's. */
    public function officeOf(Employee $employee): ?string
    {
        $current = $employee->currentAssignment?->organization_id;
        if ($current !== null) {
            return (string) $current;
        }
        $last = EmployeeAssignment::query()->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')->orderByDesc('created_at')->value('organization_id');

        return $last === null ? null : (string) $last;
    }

    public function inScope(User $user, ?string $organizationId): bool
    {
        return $organizationId !== null && $this->scope->canAccessOrganization($user, $organizationId);
    }

    public function isSelf(User $user, Employee $employee): bool
    {
        return $this->employeeOf($user)?->id === $employee->id;
    }

    public function canSeeEmployee(User $user, Employee $employee): bool
    {
        return $user->can('work_experience.view') && $this->inScope($user, $this->officeOf($employee));
    }

    public function canRecord(User $user, Employee $employee): bool
    {
        return $user->can('work_experience.records.manage')
            && $this->inScope($user, $this->officeOf($employee))
            && ! $this->isSelf($user, $employee);
    }

    public function isOwner(User $user, WorkExperienceRequest $request): bool
    {
        $employee = $this->employeeOf($user);

        return $employee !== null && $employee->id === $request->employee_id;
    }

    public function canView(User $user, WorkExperienceRequest $request): bool
    {
        if ($this->isOwner($user, $request) && $user->can('work_experience.request_own')) {
            return true;
        }

        return $user->can('work_experience.view') && $this->inScope($user, $request->organization_id);
    }

    /** Office work on a request: accept, send back, print, record the sending. Never on one's own request. */
    public function canManage(User $user, WorkExperienceRequest $request): bool
    {
        return $user->can('work_experience.requests.manage')
            && $this->inScope($user, $request->organization_id)
            && ! $this->isOwner($user, $request);
    }

    /** Requests of the institutions in the user's scope. */
    public function constrainRequests(Builder $query, User $user): Builder
    {
        return $this->scope->applyOrganizationScope($query, $user, 'work_experience_requests.organization_id');
    }

    /** Employees whose record is kept by an institution in the user's scope, including those who have left. */
    public function constrainEmployees(Builder $query, User $user): Builder
    {
        if ($this->scope->isUnrestricted($user)) {
            return $query;
        }
        $allowed = $this->scope->allowedOrganizationIds($user);

        return $query->where(function (Builder $where) use ($allowed): void {
            $where->whereIn('employees.current_assignment_id', EmployeeAssignment::query()->select('id')->whereIn('organization_id', $allowed))
                ->orWhere(function (Builder $left) use ($allowed): void {
                    $left->whereNull('employees.current_assignment_id')
                        ->whereIn('employees.id', EmployeeAssignment::query()->select('employee_id')->whereIn('organization_id', $allowed));
                });
        });
    }

    /** @return array<int, string> the institutions whose settings the user may manage; empty means every institution */
    public function organizationsInScope(User $user): ?array
    {
        return $this->scope->isUnrestricted($user) ? null : $this->scope->allowedOrganizationIds($user);
    }
}
