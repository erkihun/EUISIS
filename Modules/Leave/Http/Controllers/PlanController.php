<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\DailyWork\Contracts\DailyWorkReviewers;
use Modules\Leave\Application\Services\LeaveAccess;
use Modules\Leave\Application\Services\LeaveCalendar;
use Modules\Leave\Application\Services\LeavePlanService;
use Modules\Leave\Application\Services\LeavePresenter;
use Modules\Leave\Domain\Models\LeavePlan;

/**
 * The yearly leave schedule. An employee states, at the start of the budget
 * year, when they mean to take their annual leave (My Portal); supervisors
 * see the schedule of their team and HR officers that of the institution.
 */
class PlanController extends Controller
{
    public function __construct(
        private readonly LeaveAccess $access,
        private readonly LeavePlanService $plans,
        private readonly LeaveCalendar $calendar,
        private readonly LeavePresenter $presenter,
    ) {}

    /** The schedule of the team (supervisor) or of the institutions in scope (HR). */
    public function index(Request $request, OrganizationScopeService $scope, DailyWorkReviewers $reviewers): Response
    {
        $user = $request->user();
        $wholeInstitution = $user->can('leave.view_org');
        abort_unless($wholeInstitution || $user->can('leave.view_team'), 403);

        $currentYear = $this->calendar->budgetYearOf($this->calendar->today());
        $year = (int) $request->query('year', (string) $currentYear);
        $year = in_array($year, [$currentYear - 1, $currentYear, $currentYear + 1], true) ? $year : $currentYear;
        $search = is_string($request->query('search')) ? trim((string) $request->query('search')) : '';
        $status = in_array($request->query('status'), [LeavePlan::STATUS_PENDING, LeavePlan::STATUS_APPROVED, LeavePlan::STATUS_REJECTED], true) ? (string) $request->query('status') : '';

        // An officer sees the institutions in scope; a supervisor, the people whose work they review.
        $visible = function (Builder $query) use ($user, $wholeInstitution, $scope, $reviewers): Builder {
            return $wholeInstitution
                ? $scope->applyOrganizationScope($query, $user, $query->qualifyColumn('organization_id'))
                : $reviewers->coverage($user)->apply($query);
        };

        $query = $visible(LeavePlan::query()->with(['employee', 'leaveType:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'request:id,reference_number,status']))
            ->where('budget_year', $year)
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->whereHas('employee', fn (Builder $e) => $e->where('employee_number', 'like', $like)->orWhere('full_name', 'like', $like)->orWhere('name_en', 'like', $like));
            });

        $withPlan = $visible(LeavePlan::query())->where('budget_year', $year)->distinct()->count('employee_id');
        $staff = null;
        if ($wholeInstitution) {
            $employees = Employee::query()->where('status', EmployeeStatus::Active->value);
            $inScope = $scope->employeeIdsInScope($user);
            if ($inScope !== null) {
                $employees->whereIn('id', $inScope);
            }
            $staff = $employees->count();
        }

        return Inertia::render('modules/leave/Plans', [
            'leavePlans' => $query->orderBy('starts_on')->orderBy('id')->paginate(25)->withQueryString()->through(fn (LeavePlan $plan): array => [
                ...$this->plans->present($plan),
                'employee' => $this->presenter->employee($plan->employee),
                'can_decide' => $plan->status === LeavePlan::STATUS_PENDING && $this->plans->canDecide($user, $plan),
                'unit' => $plan->organizationUnit === null ? null : ['id' => $plan->organizationUnit->id, 'name_en' => $plan->organizationUnit->name_en, 'name_am' => $plan->organizationUnit->name_am],
            ]),
            'filters' => ['year' => $year, 'search' => $search, 'status' => $status],
            'planYears' => [$currentYear - 1, $currentYear, $currentYear + 1],
            'planSummary' => [
                'year' => $year,
                'starts_on' => $this->calendar->budgetYearStart($year),
                'ends_on' => $this->calendar->budgetYearEnd($year),
                'employees_with_plan' => $withPlan,
                'waiting' => $visible(LeavePlan::query())->where('budget_year', $year)->where('status', LeavePlan::STATUS_PENDING)->count(),
                // Known for an officer only: a supervisor's team is not a fixed list.
                'employees_without_plan' => $staff === null ? null : max(0, $staff - $withPlan),
            ],
        ]);
    }

    /** Approve a waiting period of the schedule, or send it back with the reason. */
    public function decide(Request $request, LeavePlan $leavePlan): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            // Sending a period back always says why.
            'comment' => ['nullable', 'required_if:decision,reject', 'string', 'max:1000'],
        ], [], ['comment' => __('leave::messages.fields.comment')]);

        $this->plans->decide($request->user(), $leavePlan, $data['decision'] === 'approve', $data['comment'] ?? null);

        return back()->with('success', __($data['decision'] === 'approve' ? 'leave::messages.flash.plan_approved' : 'leave::messages.flash.plan_rejected'));
    }

    /** My Portal: add a period to my own schedule. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave.request_own'), 403);
        $employee = $this->access->employeeOf($user);
        abort_if($employee === null, 403);

        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['starts_on' => __('leave::messages.fields.starts_on'), 'ends_on' => __('leave::messages.fields.ends_on'), 'note' => __('leave::messages.fields.note')]);

        $this->plans->add($user, $employee, $data);

        return back()->with('success', __('leave::messages.flash.plan_added'));
    }

    /** My Portal: take a period off my own schedule. */
    public function destroy(Request $request, LeavePlan $leavePlan): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave.request_own') && $this->access->employeeOf($user)?->id === $leavePlan->employee_id, 403);

        $this->plans->remove($leavePlan);

        return back()->with('success', __('leave::messages.flash.plan_removed'));
    }
}
