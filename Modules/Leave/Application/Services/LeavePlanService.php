<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeWorkContextResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\DailyWork\Contracts\DailyWorkReviewers;
use Modules\Leave\Domain\Enums\LeaveCategory;
use Modules\Leave\Domain\Models\LeavePlan;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveType;

/**
 * The yearly leave schedule: at the start of the budget year every employee
 * tells the institution when they mean to take their annual leave, so the
 * unit can plan around it, and the institution approves it: each planned
 * period waits for the decision of the employee's supervisor (or, where no
 * supervisor reviews the employee, of an HR officer). A planned period holds
 * no days and spends no balance; once approved the employee asks for it,
 * and that request is decided as any other.
 */
class LeavePlanService
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly LeaveDayCounter $counter,
        private readonly EmployeeWorkContextResolver $context,
        private readonly DailyWorkReviewers $reviewers,
        private readonly OrganizationScopeService $scope,
        private readonly LeaveNotifier $notifier,
        private readonly WriteAuditLogAction $audit,
        private readonly LeaveSettings $settings,
    ) {}

    /** The leave type the schedule is made for: annual leave. */
    public function planType(): ?LeaveType
    {
        return LeaveType::query()->active()->where('category', LeaveCategory::Annual->value)->orderBy('sort_order')->first();
    }

    /**
     * Add one planned period to the employee's schedule.
     *
     * @param  array{starts_on: string, ends_on: string, note?: ?string}  $data
     */
    public function add(User $actor, Employee $employee, array $data): LeavePlan
    {
        if (! $this->settings->bool('schedule_enabled')) {
            throw ValidationException::withMessages(['starts_on' => __('leave::messages.errors.schedule_disabled')]);
        }
        $type = $this->planType();
        if ($type === null) {
            throw ValidationException::withMessages(['starts_on' => __('leave::messages.errors.type_unavailable')]);
        }
        $today = $this->calendar->today();
        $startsOn = Carbon::parse($data['starts_on'])->toDateString();
        $endsOn = Carbon::parse($data['ends_on'])->toDateString();
        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => __('leave::messages.errors.ends_before_start')]);
        }
        if ($startsOn < $today) {
            throw ValidationException::withMessages(['starts_on' => __('leave::messages.errors.starts_in_past')]);
        }
        // A period belongs to the budget year it starts in, and stays inside it.
        $budgetYear = $this->calendar->budgetYearOf($startsOn);
        if ($endsOn > $this->calendar->budgetYearEnd($budgetYear)) {
            throw ValidationException::withMessages(['ends_on' => __('leave::messages.errors.plan_crosses_year')]);
        }
        $days = $this->counter->total($this->counter->days($type, $startsOn, $endsOn));
        if ($days <= 0) {
            throw ValidationException::withMessages(['starts_on' => __('leave::messages.errors.no_leave_days')]);
        }
        // A period that was sent back no longer stands in the way of planning those days again.
        $overlaps = LeavePlan::query()->where('employee_id', $employee->id)->where('status', '!=', LeavePlan::STATUS_REJECTED)
            ->whereDate('starts_on', '<=', $endsOn)->whereDate('ends_on', '>=', $startsOn)->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['starts_on' => __('leave::messages.errors.plan_overlaps')]);
        }

        $assignment = $this->context->assignmentOn($employee, Carbon::parse($today));
        $needsApproval = $this->settings->bool('schedule_requires_approval');
        $plan = new LeavePlan;
        $plan->fill(['note' => $data['note'] ?? null]);
        $plan->forceFill([
            'employee_id' => $employee->id,
            'organization_id' => $assignment?->organization_id,
            'organization_unit_id' => $assignment?->organization_unit_id,
            'leave_type_id' => $type->id,
            'budget_year' => $budgetYear,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'days_counted' => $days,
            // Settings page: with schedule approval switched off, a planned period stands as soon as it is added.
            'status' => $needsApproval ? LeavePlan::STATUS_PENDING : LeavePlan::STATUS_APPROVED,
            'decided_at' => $needsApproval ? null : now(),
            'created_by' => $actor->getKey(),
        ])->save();

        $manager = $assignment === null ? null : $this->reviewers->directManager($employee, $assignment, Carbon::parse($today));
        if ($needsApproval && $manager !== null && (int) $manager->getKey() !== (int) $actor->getKey()) {
            $this->notifier->planNotice($manager, 'plan_submitted', $plan);
        }

        return $plan;
    }

    /** Take a period off the schedule. One that already became a request stays, as the record of what was planned. */
    public function remove(LeavePlan $plan): void
    {
        if ($plan->leave_request_id !== null) {
            throw ValidationException::withMessages(['plan' => __('leave::messages.errors.plan_already_requested')]);
        }
        // An approved period is changed before its first day; from that day the system counts it as leave.
        if ($this->settings->bool('schedule_auto_count') && $plan->status === LeavePlan::STATUS_APPROVED && $plan->starts_on->toDateString() <= $this->calendar->today()) {
            throw ValidationException::withMessages(['plan' => __('leave::messages.errors.plan_already_started')]);
        }
        $plan->delete();
    }

    /**
     * May the user approve or send back this planned period? The supervisor
     * who reviews the employee's work, or an HR officer of the institution;
     * never the employee themself.
     */
    public function canDecide(User $user, LeavePlan $plan): bool
    {
        if ($user->employee_id !== null && $user->employee_id === $plan->employee_id) {
            return false;
        }
        if ($user->can('leave.approve') && $this->reviewers->coverage($user)->apply(LeavePlan::query()->whereKey($plan->getKey()))->exists()) {
            return true;
        }

        return $user->can('leave.manage') && $plan->organization_id !== null && $this->scope->canAccessOrganization($user, $plan->organization_id);
    }

    /** Approve a waiting period, or send it back with the reason. */
    public function decide(User $actor, LeavePlan $plan, bool $approve, ?string $comment): LeavePlan
    {
        if (! $this->canDecide($actor, $plan)) {
            throw new AuthorizationException;
        }
        if ($plan->status !== LeavePlan::STATUS_PENDING) {
            throw ValidationException::withMessages(['plan' => __('leave::messages.errors.plan_already_decided')]);
        }
        $plan->forceFill([
            'status' => $approve ? LeavePlan::STATUS_APPROVED : LeavePlan::STATUS_REJECTED,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
            'decision_comment' => $comment,
        ])->save();
        $this->audit->execute(AuditEventType::LeavePlanDecided, $actor, $plan, $plan->organization_id, ['status' => LeavePlan::STATUS_PENDING], [
            'status' => $plan->status, 'starts_on' => $plan->starts_on->toDateString(), 'ends_on' => $plan->ends_on->toDateString(), 'budget_year' => $plan->budget_year,
        ], $comment);
        $this->notifier->planDecided($plan, $approve ? 'plan_approved' : 'plan_rejected');

        return $plan;
    }

    /** The employee asked for an approved period: remember which request it became. */
    public function markRequested(Employee $employee, ?string $planId, LeaveRequest $request): void
    {
        if ($planId === null || $planId === '') {
            return;
        }
        LeavePlan::query()->whereKey($planId)->where('employee_id', $employee->id)->where('status', LeavePlan::STATUS_APPROVED)->whereNull('leave_request_id')
            ->update(['leave_request_id' => $request->id]);
    }

    /**
     * An employee's schedule for the budget year of today.
     *
     * @return array{budget_year: int, year_starts_on: string, year_ends_on: string, planned_days: float, rows: list<array<string, mixed>>}
     */
    public function scheduleOf(Employee $employee): array
    {
        $budgetYear = $this->calendar->budgetYearOf($this->calendar->today());
        $plans = LeavePlan::query()->with(['leaveType:id,name_en,name_am', 'request:id,reference_number,status'])
            ->where('employee_id', $employee->id)->where('budget_year', $budgetYear)->orderBy('starts_on')->get();

        return [
            'budget_year' => $budgetYear,
            'year_starts_on' => $this->calendar->budgetYearStart($budgetYear),
            'year_ends_on' => $this->calendar->budgetYearEnd($budgetYear),
            'planned_days' => round((float) $plans->where('status', '!=', LeavePlan::STATUS_REJECTED)->sum('days_counted'), 1),
            'rows' => $plans->map(fn (LeavePlan $plan): array => $this->present($plan))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(LeavePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'budget_year' => $plan->budget_year,
            'starts_on' => $plan->starts_on->toDateString(),
            'ends_on' => $plan->ends_on->toDateString(),
            'days_counted' => $plan->days_counted,
            'note' => $plan->note,
            'status' => $plan->status,
            'decision_comment' => $plan->decision_comment,
            // Why the system could not count this approved period as leave on its first day.
            'auto_error' => $plan->leave_request_id === null ? $plan->auto_error : null,
            'leave_type' => ['id' => $plan->leave_type_id, 'name_en' => $plan->leaveType?->name_en, 'name_am' => $plan->leaveType?->name_am],
            'request' => $plan->request === null ? null : [
                'id' => $plan->request->id, 'reference_number' => $plan->request->reference_number, 'status' => $plan->request->status->value,
            ],
        ];
    }
}
