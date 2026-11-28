<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Domain\Enums\LeaveCategory;
use Modules\Leave\Domain\Models\LeaveRequest;

/**
 * Who may see and do what with a leave request (ADR 0039, decision 10).
 *
 * A permission is never enough on its own: an employee acts on their own
 * leave, a supervisor on the requests routed to them, an HR officer inside
 * their organization scope. Scope fails closed (ADR 0003). Nobody decides
 * their own request.
 */
class LeaveAccess
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function employeeOf(User $user): ?Employee
    {
        $employee = $user->employee;

        return $employee instanceof Employee ? $employee : null;
    }

    public function isOwner(User $user, LeaveRequest $request): bool
    {
        $employee = $this->employeeOf($user);

        return $employee !== null && $employee->id === $request->employee_id;
    }

    public function isSupervisorOf(User $user, LeaveRequest $request): bool
    {
        return $request->supervisor_user_id !== null
            && (int) $request->supervisor_user_id === (int) $user->getKey()
            && ! $this->isOwner($user, $request);
    }

    public function inScope(User $user, ?string $organizationId): bool
    {
        return $organizationId !== null && $this->scope->canAccessOrganization($user, $organizationId);
    }

    public function canView(User $user, LeaveRequest $request): bool
    {
        if ($this->isOwner($user, $request) && $user->can('leave.view_own')) {
            return true;
        }
        if ($this->isSupervisorOf($user, $request) && $user->can('leave.view_team')) {
            return true;
        }

        return $user->can('leave.view_org') && $this->inScope($user, $request->organization_id);
    }

    public function canDecideAsSupervisor(User $user, LeaveRequest $request): bool
    {
        return $request->status === LeaveStatus::PendingSupervisor
            && $user->can('leave.approve')
            && $this->isSupervisorOf($user, $request);
    }

    /** HR work on a request: confirm, refuse, end early, attach evidence. Never on one's own leave. */
    public function canManage(User $user, LeaveRequest $request): bool
    {
        return $user->can('leave.manage')
            && $this->inScope($user, $request->organization_id)
            && ! $this->isOwner($user, $request);
    }

    public function canDecideAsHr(User $user, LeaveRequest $request): bool
    {
        return $request->status === LeaveStatus::PendingHr && $this->canManage($user, $request);
    }

    public function canCancel(User $user, LeaveRequest $request, string $today): bool
    {
        if ($request->status->isPending()) {
            return ($this->isOwner($user, $request) && $user->can('leave.cancel_own')) || $this->canManage($user, $request);
        }
        if ($request->status !== LeaveStatus::Approved || $request->starts_on->toDateString() <= $today) {
            return false;
        }

        // Approved and not yet begun: the employee may still withdraw it, and so may HR.
        return ($this->isOwner($user, $request) && $user->can('leave.cancel_own')) || $this->canManage($user, $request);
    }

    public function canEndEarly(User $user, LeaveRequest $request, string $today): bool
    {
        return $request->status === LeaveStatus::Approved
            && $request->starts_on->toDateString() <= $today
            && $request->ends_on->toDateString() >= $today
            && $this->canManage($user, $request);
    }

    /**
     * The reason and the evidence of a sick or maternity leave are health
     * data: the employee and HR officers holding leave.view_medical read
     * them; a supervisor sees the dates and the type only.
     */
    public function canSeeSensitive(User $user, LeaveRequest $request): bool
    {
        if ($this->isOwner($user, $request)) {
            return true;
        }
        $request->loadMissing('leaveType');
        if (in_array($request->leaveType?->category, [LeaveCategory::Sick, LeaveCategory::Maternity], true)) {
            return $user->can('leave.view_medical') && $this->inScope($user, $request->organization_id);
        }

        return $this->canView($user, $request);
    }

    /** May the user work on this employee's leave as an HR officer (register, adjust, read the ledger)? */
    public function canManageEmployee(User $user, Employee $employee, string $permission = 'leave.manage'): bool
    {
        return $user->can($permission) && $this->scope->canAccessEmployee($user, $employee);
    }

    /** Requests of the institutions in the user's scope. */
    public function constrainToScope(Builder $query, User $user): Builder
    {
        return $this->scope->applyOrganizationScope($query, $user, 'leave_requests.organization_id');
    }
}
