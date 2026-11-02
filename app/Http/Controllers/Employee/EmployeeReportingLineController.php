<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportingLines\RevokeTemporaryAuthorityRequest;
use App\Http\Requests\ReportingLines\StoreSupervisorDelegationRequest;
use App\Models\ActingAppointment;
use App\Models\Employee;
use App\Models\SupervisorDelegation;
use App\Services\ReportingLines\ReportingLinePresenter;
use App\Services\ReportingLines\ReportingLineResolver;
use App\Services\ReportingLines\SupervisorDelegationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Portal (ADR 0025): the signed-in employee's own supervisor and chain
 * (no permission: the record is their own), and their team — direct and
 * indirect reports — which needs reporting-lines.view_team on top of the
 * resolved relationship. Delegating one's own actions needs
 * reporting-lines.delegate. No route takes an employee id.
 */
class EmployeeReportingLineController extends Controller
{
    public function __construct(
        private readonly ReportingLineResolver $resolver,
        private readonly ReportingLinePresenter $presenter,
        private readonly SupervisorDelegationService $delegations,
    ) {}

    public function line(Request $request): Response
    {
        $employee = $this->employee($request);
        $employee->load(['currentAssignment.position', 'currentAssignment.organizationUnit', 'currentAssignment.organization']);

        return Inertia::render('Employee/ReportingLine', [
            'employee' => $this->presenter->employee($employee),
            'supervisor' => $this->presenter->resolution($this->resolver->directSupervisorOf($employee)),
            'chain' => $this->presenter->chain($this->resolver->chainOf($employee)),
            'acting_as' => ActingAppointment::query()->where('employee_id', $employee->getKey())->inForceOn()->with(['position.organizationUnit', 'employee', 'organization', 'authorizer'])->get()->map(fn (ActingAppointment $a): array => $this->presenter->actingAppointment($a))->all(),
            'can' => ['view_team' => $request->user()->can('reportingLinesViewTeam', $employee)],
        ]);
    }

    public function team(Request $request): Response
    {
        $employee = $this->employee($request);
        $this->authorize('reportingLinesViewTeam', $employee);
        $employee->load(['currentAssignment.position', 'currentAssignment.organizationUnit', 'currentAssignment.organization']);

        $direct = $this->presenter->employeePage($this->resolver->directReportsQuery($employee), 25, 'page');
        $all = $this->resolver->allReportsQuery($employee);
        $allTotal = (clone $all['query'])->count();
        $indirect = $request->boolean('indirect')
            ? $this->presenter->employeePage($all['query']->whereNotIn('id', $this->resolver->directReportsQuery($employee)->select('id')), 25, 'indirect_page')
            : null;

        return Inertia::render('Employee/MyTeam', [
            'employee' => $this->presenter->employee($employee),
            'direct' => $direct,
            'indirect' => $indirect,
            'indirect_total' => max(0, $allTotal - $direct['meta']['total']),
            'indirect_truncated' => $all['truncated'],
            'delegations' => SupervisorDelegation::query()->where('delegator_employee_id', $employee->getKey())->with(['delegator', 'delegate', 'organization', 'authorizer', 'revoker'])->latest('starts_on')->limit(50)->get()->map(fn (SupervisorDelegation $d): array => $this->presenter->delegation($d))->all(),
            'received_delegations' => SupervisorDelegation::query()->where('delegate_employee_id', $employee->getKey())->inForceOn()->with(['delegator', 'delegate', 'organization', 'authorizer'])->get()->map(fn (SupervisorDelegation $d): array => $this->presenter->delegation($d))->all(),
            'actions' => $this->presenter->actionOptions(),
            'can' => ['delegate' => $request->user()->can('reporting-lines.delegate')],
        ]);
    }

    public function storeDelegation(StoreSupervisorDelegationRequest $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $this->delegations->delegate($request->user(), $employee, $request->validated());

        return redirect()->route('employee.my-team')->with('success', __('reporting-lines.flash.delegation_created'));
    }

    public function revokeDelegation(RevokeTemporaryAuthorityRequest $request, SupervisorDelegation $supervisorDelegation): RedirectResponse
    {
        $employee = $this->employee($request);
        abort_unless((string) $supervisorDelegation->delegator_employee_id === (string) $employee->getKey(), 403);
        $this->authorize('revoke', $supervisorDelegation);
        $this->delegations->revoke($request->user(), $supervisorDelegation, $request->string('reason')->toString());

        return redirect()->route('employee.my-team')->with('success', __('reporting-lines.flash.delegation_revoked'));
    }

    private function employee(Request $request): Employee
    {
        $employeeId = $request->user()->employee_id;
        abort_if($employeeId === null, 404);

        return Employee::query()->findOrFail($employeeId);
    }
}
