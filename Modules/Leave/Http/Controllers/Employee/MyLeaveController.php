<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Leave\Application\Services\LeaveAccess;
use Modules\Leave\Application\Services\LeaveBalanceService;
use Modules\Leave\Application\Services\LeaveCalendar;
use Modules\Leave\Application\Services\LeavePlanService;
use Modules\Leave\Application\Services\LeavePresenter;
use Modules\Leave\Application\Services\LeaveRequestService;
use Modules\Leave\Application\Services\LeaveSettings;
use Modules\Leave\Application\Services\PlannedLeaveStarter;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Http\Requests\SaveLeaveRequest;
use Modules\Leave\Http\Support\LeavePopups;
use Modules\Leave\Http\Support\StoresLeaveDocuments;

/**
 * My Portal > My leave: the signed-in employee's own balances and requests.
 *
 * No route here accepts an employee id. The employee is always the user's
 * linked employee record, and a request id is re-checked against it, so
 * another employee's leave is a 403.
 */
class MyLeaveController extends Controller
{
    use StoresLeaveDocuments;

    public function __construct(
        private readonly LeaveAccess $access,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveRequestService $service,
        private readonly LeavePresenter $presenter,
        private readonly LeaveCalendar $calendar,
        private readonly LeavePopups $popups,
        private readonly LeavePlanService $plans,
        private readonly PlannedLeaveStarter $starter,
    ) {}

    public function index(Request $request, LeaveSettings $settings): Response
    {
        $user = $request->user();
        abort_unless($user->can('leave.view_own'), 403);
        $employee = $this->access->employeeOf($user);
        if ($employee !== null) {
            // An approved planned period whose first day has come is counted as leave, also when the nightly run has not passed yet.
            $this->starter->run($employee);
        }

        return Inertia::render('modules/leave/MyLeave', [
            'hasEmployee' => $employee !== null,
            'leaveSummary' => $employee === null ? null : $this->balances->summary($employee),
            'leaveRequests' => $employee === null ? null : LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->with(['employee', 'leaveType', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am'])
                ->orderByDesc('starts_on')->orderByDesc('created_at')
                ->paginate(15)->withQueryString()
                ->through(fn (LeaveRequest $row): array => $this->presenter->row($row)),
            'can' => ['request' => $employee !== null && $user->can('leave.request_own')],
            'leaveEmployee' => $this->presenter->employee($employee),
            // The periods the employee said, for this budget year, they mean to take.
            'leaveSchedule' => $employee === null || ! $settings->bool('schedule_enabled') ? null : [...$this->plans->scheduleOf($employee), 'auto_count' => $settings->bool('schedule_auto_count')],
            // The popup on one of the employee's own requests.
            'leaveOpenRequest' => $this->popups->request($request, ownOnly: true),
            'today' => $this->calendar->today(),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('leave.request_own'), 403);
        $employee = $this->access->employeeOf($user);
        abort_if($employee === null, 403);

        return Inertia::render('modules/leave/RequestForm', [
            'formMode' => 'own',
            'leaveEmployee' => $this->presenter->employee($employee),
            'leaveSummary' => $this->balances->summary($employee),
            'today' => $this->calendar->today(),
        ]);
    }

    public function store(SaveLeaveRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave.request_own'), 403);
        $employee = $this->access->employeeOf($user);
        abort_if($employee === null, 403);

        $leaveRequest = $this->service->submit($user, $employee, $request->payload());
        $this->storeDocuments($this->service, $user, $leaveRequest, $request->file('documents'));
        // Asked for from the schedule: the planned period remembers the request it became.
        $this->plans->markRequested($employee, is_string($request->input('plan_id')) ? $request->input('plan_id') : null, $leaveRequest);

        // From the popup on My leave the employee stays on that page; from the request page the new request opens.
        $next = $request->boolean('stay') ? back() : redirect()->route('employee.leave.show', $leaveRequest);

        return $next->with('success', __('leave::messages.flash.submitted'));
    }

    public function show(Request $request, LeaveRequest $leaveRequest): Response
    {
        $user = $request->user();
        abort_unless($this->access->isOwner($user, $leaveRequest) && $user->can('leave.view_own'), 403);

        return Inertia::render('modules/leave/RequestShow', [
            'leaveRequest' => $this->presenter->detail($leaveRequest, $user),
            'viewContext' => 'own',
            'today' => $this->calendar->today(),
        ]);
    }
}
