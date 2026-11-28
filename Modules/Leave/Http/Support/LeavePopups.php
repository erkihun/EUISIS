<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Support;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Leave\Application\Services\LeaveAccess;
use Modules\Leave\Application\Services\LeaveBalanceService;
use Modules\Leave\Application\Services\LeavePresenter;
use Modules\Leave\Domain\Models\LeaveRequest;

/**
 * What the popups of the leave pages show. Every list page opens the same
 * two popups: one leave request (?request=<id>) and one employee's leave
 * (?selected=<id>). The record is named in the query string, so a save that
 * comes "back" to the page brings the popup's fresh data with it.
 *
 * Asking for a record one may not see is a 403 here, exactly as on the
 * record's own page.
 */
final class LeavePopups
{
    public function __construct(
        private readonly LeaveAccess $access,
        private readonly LeavePresenter $presenter,
        private readonly LeaveBalanceService $balances,
    ) {}

    /**
     * The request a popup is open on, or null when none is asked for.
     *
     * @param  bool  $ownOnly  My Portal: only the signed-in employee's own requests
     * @return array{detail: array<string, mixed>, balance: array{available: float, pending: float, free: float}|null}|null
     */
    public function request(Request $request, bool $ownOnly = false): ?array
    {
        $id = $request->query('request');
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }
        $user = $request->user();
        $leaveRequest = LeaveRequest::query()->find($id);
        abort_if($leaveRequest === null, 404);
        abort_unless(
            $ownOnly ? ($this->access->isOwner($user, $leaveRequest) && $user->can('leave.view_own')) : $this->access->canView($user, $leaveRequest),
            403,
        );

        return [
            'detail' => $this->presenter->detail($leaveRequest, $user),
            'balance' => $ownOnly ? null : $this->balanceOf($leaveRequest),
        ];
    }

    /**
     * The employee a popup is open on (opening balance, ledger,
     * postponement), or null when none is asked for.
     *
     * @return array<string, mixed>|null
     */
    public function employee(Request $request): ?array
    {
        $id = $request->query('selected');
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }
        $user = $request->user();
        $employee = Employee::query()->find($id);
        abort_if($employee === null || ! $this->access->canManageEmployee($user, $employee, 'leave.view_org'), 403);

        return [
            'employee' => [...(array) $this->presenter->employee($employee), 'service_start_date' => $employee->service_start_date?->toDateString()],
            'summary' => $this->balances->summary($employee),
            'ledger' => $this->balances->ledgerOf($employee, 100),
            'can' => [
                'adjust' => $this->access->canManageEmployee($user, $employee, 'leave.adjust_balance'),
                'postpone' => $this->access->canManageEmployee($user, $employee, 'leave.postpone'),
            ],
        ];
    }

    /**
     * The balance of the request's leave type, for the person deciding it.
     *
     * @return array{available: float, pending: float, free: float}|null
     */
    public function balanceOf(LeaveRequest $leaveRequest): ?array
    {
        $leaveRequest->loadMissing('leaveType', 'employee');
        if (! $leaveRequest->leaveType?->uses_balance || $leaveRequest->employee === null) {
            return null;
        }
        foreach ($this->balances->summary($leaveRequest->employee)['types'] as $type) {
            if ($type['id'] === $leaveRequest->leave_type_id) {
                return ['available' => (float) $type['available'], 'pending' => (float) $type['pending'], 'free' => (float) $type['free']];
            }
        }

        return null;
    }
}
