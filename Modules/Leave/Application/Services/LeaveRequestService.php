<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\Employees\EmployeeWorkContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\DailyWork\Contracts\DailyWorkReviewers;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Domain\Enums\LeaveCategory;
use Modules\Leave\Domain\Enums\LeaveEntryType;
use Modules\Leave\Domain\Models\LeaveDocument;
use Modules\Leave\Domain\Models\LeavePlan;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveRequestDay;
use Modules\Leave\Domain\Models\LeaveRequestEvent;
use Modules\Leave\Domain\Models\LeaveRule;
use Modules\Leave\Domain\Models\LeaveType;

/**
 * The leave workflow (ADR 0039, decision 7).
 *
 *   employee asks ─► supervisor decides ─► HR officer confirms ─► approved
 *                    (types that need it)   (balance is spent here)
 *
 * Sick, maternity and paternity leave skip the supervisor: they are
 * entitlements, registered from the evidence. Every step that reads or
 * spends a balance runs inside one transaction holding the employee's row
 * lock. Nothing is edited in place after approval: a cancellation or an
 * early return gives days back through the ledger.
 */
class LeaveRequestService
{
    private const DAYS_PER_MONTH = 30;

    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly LeaveRules $rules,
        private readonly LeaveDayCounter $counter,
        private readonly LeaveLedger $ledger,
        private readonly LeaveGrantService $grants,
        private readonly LeaveAccess $access,
        private readonly LeaveNotifier $notifier,
        private readonly EmployeeWorkContextResolver $context,
        private readonly DailyWorkReviewers $managers,
        private readonly WriteAuditLogAction $audit,
        private readonly LeaveSettings $settings,
    ) {}

    /**
     * Ask for leave. Only the employee asks, signed in with their own account
     * (owner rule, 2026-11-27): nobody enters a leave request for someone else.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(User $actor, Employee $employee, array $data): LeaveRequest
    {
        $today = $this->calendar->today();
        $type = LeaveType::query()->active()->find((string) ($data['leave_type_id'] ?? ''));
        if ($type === null) {
            throw $this->invalid('leave_type_id', 'type_unavailable');
        }
        if (! $type->employee_can_request) {
            throw $this->invalid('leave_type_id', 'type_hr_only');
        }
        if (! $type->openTo($employee->gender)) {
            throw $this->invalid('leave_type_id', 'type_not_open');
        }
        if ($employee->status !== EmployeeStatus::Active) {
            throw $this->invalid('employee', 'employee_not_active');
        }
        $assignment = $this->context->assignmentOn($employee, Carbon::parse($today));
        if ($assignment === null) {
            throw $this->invalid('employee', 'no_assignment');
        }

        $halfDay = (bool) ($data['is_half_day'] ?? false);
        $startsOn = Carbon::parse((string) $data['starts_on'])->toDateString();
        // Half days may run over several days: half a day on each counted day of the period.
        $endsOn = Carbon::parse((string) ($data['ends_on'] ?? $data['starts_on']))->toDateString();
        $expectedDelivery = empty($data['expected_delivery_on']) ? null : Carbon::parse((string) $data['expected_delivery_on'])->toDateString();

        $rule = $this->rules->ruleFor($type, $startsOn);
        if ($rule === null) {
            throw $this->invalid('leave_type_id', 'no_rule');
        }
        if ($halfDay && ! $type->allows_half_day) {
            throw $this->invalid('is_half_day', 'half_day_not_allowed');
        }
        // Leave is asked for ahead of time: a request never starts before the day it is made (owner, 2026-11-27),
        // and the settings page may ask for some days of notice. A type that "may start in the past" (sickness,
        // a birth) is reported after it began and is exempt from both.
        if (! $type->allows_past_start) {
            if ($startsOn < $today) {
                throw $this->invalid('starts_on', 'starts_in_past');
            }
            $notice = $this->settings->int('min_notice_days');
            if ($notice > 0 && $startsOn < $this->calendar->addDays($today, $notice)) {
                throw $this->invalid('starts_on', 'notice_required', ['days' => $notice]);
            }
        }

        if ($type->category === LeaveCategory::Maternity) {
            [$startsOn, $endsOn] = $this->maternityPeriod($rule, $startsOn, $endsOn, $expectedDelivery);
        }
        if ($endsOn < $startsOn) {
            throw $this->invalid('ends_on', 'ends_before_start');
        }

        $rows = $this->counter->days($type, $startsOn, $endsOn, $halfDay);
        $total = $this->counter->total($rows);
        if ($total <= 0) {
            throw $this->invalid('starts_on', 'no_leave_days');
        }
        $budgetYear = $this->calendar->budgetYearOf($startsOn);

        $request = DB::transaction(function () use ($actor, $employee, $assignment, $type, $rule, $data, $halfDay, $startsOn, $endsOn, $expectedDelivery, $rows, $total, $budgetYear, $today): LeaveRequest {
            /** @var EmployeeAssignment $assignment */
            $this->lockEmployee($employee);
            $this->grants->settle($employee);
            $this->assertNoOverlap($employee->id, $startsOn, $endsOn);
            $payBreakdown = $this->assertWithinLimits($employee, $type, $rule, $total, $startsOn, $budgetYear);

            $supervisor = null;
            $basis = null;
            if ($type->requires_supervisor_approval) {
                $resolution = $this->managers->directManagerResolution($employee, $assignment, Carbon::parse($today));
                $candidate = $resolution->user;
                // Nobody decides their own leave, and a closed account decides nothing: the request then goes to HR.
                if ($candidate !== null && $candidate->status === 'active' && (int) $candidate->getKey() !== (int) $actor->getKey() && $candidate->employee_id !== $employee->id) {
                    $supervisor = $candidate;
                    $basis = $resolution->basis;
                }
            }

            $request = new LeaveRequest;
            $request->fill(['reason' => $data['reason'] ?? null, 'contact_during_leave' => $data['contact_during_leave'] ?? null]);
            $request->forceFill([
                'reference_number' => $this->reference($budgetYear),
                'employee_id' => $employee->id,
                'requester_user_id' => $actor->getKey(),
                'organization_id' => $assignment->organization_id,
                'organization_unit_id' => $assignment->organization_unit_id,
                'position_id' => $assignment->position_id,
                'leave_type_id' => $type->id,
                'leave_rule_set_id' => $rule->leave_rule_set_id,
                'budget_year' => $budgetYear,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'is_half_day' => $halfDay,
                'half_day_part' => $halfDay ? (($data['half_day_part'] ?? null) === LeaveRequest::HALF_AFTERNOON ? LeaveRequest::HALF_AFTERNOON : LeaveRequest::HALF_MORNING) : null,
                'days_counted' => $total,
                'pay_breakdown' => $payBreakdown,
                'expected_delivery_on' => $expectedDelivery,
                'status' => $supervisor !== null ? LeaveStatus::PendingSupervisor : LeaveStatus::PendingHr,
                'registered_by_hr' => false,
                'supervisor_user_id' => $supervisor?->getKey(),
                'supervisor_basis' => $basis,
                'submitted_at' => now(),
            ])->save();

            $this->writeDays($request, $rows, approved: false);
            $this->event($request, 'submitted', $actor, null, ['days' => $total]);
            $this->audit->execute(AuditEventType::LeaveRequested, $actor, $request, $request->organization_id, null, [
                'reference_number' => $request->reference_number, 'leave_type' => $type->code, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
                'days' => $total, 'status' => $request->status->value,
            ]);

            return $request;
        });

        if ($request->status === LeaveStatus::PendingSupervisor) {
            $this->notifier->approvalRequired($request);
        } else {
            $this->notifier->confirmationRequired($request);
        }

        return $request;
    }

    public function decideAsSupervisor(User $actor, LeaveRequest $request, bool $approve, ?string $comment): LeaveRequest
    {
        if (! $this->access->canDecideAsSupervisor($actor, $request)) {
            throw new AuthorizationException;
        }
        // Settings page: with HR confirmation switched off, the supervisor's approval is the final one.
        $final = $approve && ! $this->settings->bool('hr_confirmation_required');

        $request = DB::transaction(function () use ($actor, $request, $approve, $comment, $final): LeaveRequest {
            $employee = $this->lockEmployee(Employee::query()->findOrFail($request->employee_id));
            $locked = $this->lock($request);
            if ($locked->status !== LeaveStatus::PendingSupervisor) {
                throw $this->invalid('status', 'already_decided');
            }
            if ($final) {
                $type = $locked->leaveType()->firstOrFail();
                $rule = $this->ruleOf($locked, $type);
                if ($this->needsDocument($type, $rule, (float) $locked->days_counted) && ! $locked->documents()->exists()) {
                    throw $this->invalid('documents', 'document_required');
                }
                if ($type->uses_balance) {
                    $this->grants->settle($employee);
                    $this->ledger->take($locked, $type, $rule, (int) $actor->getKey());
                }
                $locked->days()->update(['is_approved' => true]);
            }
            $locked->forceFill([
                'status' => match (true) {
                    ! $approve => LeaveStatus::Rejected,
                    $final => LeaveStatus::Approved,
                    default => LeaveStatus::PendingHr,
                },
                'supervisor_decided_by' => $actor->getKey(),
                'supervisor_decided_at' => now(),
                'supervisor_comment' => $comment,
            ])->save();
            if (! $approve) {
                $locked->days()->delete();
            }
            $this->event($locked, $approve ? 'supervisor_approved' : 'supervisor_rejected', $actor, $comment);
            $this->audit->execute(AuditEventType::LeaveSupervisorDecided, $actor, $locked, $locked->organization_id, ['status' => LeaveStatus::PendingSupervisor->value], ['status' => $locked->status->value], $comment);
            if ($final) {
                $this->audit->execute(AuditEventType::LeaveApproved, $actor, $locked, $locked->organization_id, ['status' => LeaveStatus::PendingSupervisor->value], [
                    'status' => $locked->status->value, 'days' => (float) $locked->days_counted, 'final_step' => 'supervisor',
                ], $comment);
            }

            return $locked;
        });

        match (true) {
            ! $approve => $this->notifier->toEmployee($request, 'rejected'),
            $final => $this->notifier->toEmployee($request, 'approved'),
            default => $this->notifier->confirmationRequired($request),
        };

        return $request;
    }

    /** The HR officer confirms (the balance is spent now) or refuses. */
    public function decideAsHr(User $actor, LeaveRequest $request, bool $approve, ?string $comment): LeaveRequest
    {
        if (! $this->access->canDecideAsHr($actor, $request)) {
            throw new AuthorizationException;
        }

        $request = DB::transaction(function () use ($actor, $request, $approve, $comment): LeaveRequest {
            $employee = $this->lockEmployee(Employee::query()->findOrFail($request->employee_id));
            $locked = $this->lock($request);
            if ($locked->status !== LeaveStatus::PendingHr) {
                throw $this->invalid('status', 'already_decided');
            }
            $type = $locked->leaveType()->firstOrFail();

            if ($approve) {
                $rule = $this->ruleOf($locked, $type);
                if ($this->needsDocument($type, $rule, (float) $locked->days_counted) && ! $locked->documents()->exists()) {
                    throw $this->invalid('documents', 'document_required');
                }
                if ($type->uses_balance) {
                    $this->grants->settle($employee);
                    $this->ledger->take($locked, $type, $rule, (int) $actor->getKey());
                }
                $locked->days()->update(['is_approved' => true]);
            } else {
                $locked->days()->delete();
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => $approve ? LeaveStatus::Approved : LeaveStatus::Rejected,
                'hr_decided_by' => $actor->getKey(),
                'hr_decided_at' => now(),
                'hr_comment' => $comment,
            ])->save();
            $this->event($locked, $approve ? 'approved' : 'rejected', $actor, $comment);
            $this->audit->execute($approve ? AuditEventType::LeaveApproved : AuditEventType::LeaveRejected, $actor, $locked, $locked->organization_id, ['status' => $from->value], [
                'status' => $locked->status->value, 'days' => (float) $locked->days_counted,
            ], $comment);

            return $locked;
        });

        $this->notifier->toEmployee($request, $approve ? 'approved' : 'rejected');

        return $request;
    }

    /** Withdraw a request that is still waiting, or an approved leave that has not begun. */
    public function cancel(User $actor, LeaveRequest $request, ?string $reason): LeaveRequest
    {
        if (! $this->access->canCancel($actor, $request, $this->calendar->today())) {
            throw new AuthorizationException;
        }
        $byOwner = $this->access->isOwner($actor, $request);

        $request = DB::transaction(function () use ($actor, $request, $reason): LeaveRequest {
            $this->lockEmployee(Employee::query()->findOrFail($request->employee_id));
            $locked = $this->lock($request);
            if (! in_array($locked->status, LeaveStatus::HOLDING, true)) {
                throw $this->invalid('status', 'already_decided');
            }
            $from = $locked->status;
            if ($from === LeaveStatus::Approved) {
                $this->ledger->giveBack($locked, (float) $locked->days_counted, (int) $actor->getKey(), 'Leave cancelled before it began.');
            }
            $locked->days()->delete();
            $locked->forceFill(['status' => LeaveStatus::Cancelled, 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->event($locked, 'cancelled', $actor, $reason);
            $this->audit->execute(AuditEventType::LeaveCancelled, $actor, $locked, $locked->organization_id, ['status' => $from->value], ['status' => $locked->status->value], $reason);

            return $locked;
        });

        if (! $byOwner) {
            $this->notifier->toEmployee($request, 'cancelled');
        }

        return $request;
    }

    /**
     * Cut an approved leave short: $lastDayOn is the last day of leave. The
     * days after it go back to the balance. Also the way a sickness during
     * annual leave is handled: end the annual leave, then register the sick
     * leave.
     */
    public function endEarly(User $actor, LeaveRequest $request, string $lastDayOn, string $reason): LeaveRequest
    {
        if (! $this->access->canEndEarly($actor, $request, $this->calendar->today())) {
            throw new AuthorizationException;
        }
        $lastDayOn = Carbon::parse($lastDayOn)->toDateString();

        $request = DB::transaction(function () use ($actor, $request, $lastDayOn, $reason): LeaveRequest {
            $this->lockEmployee(Employee::query()->findOrFail($request->employee_id));
            $locked = $this->lock($request);
            if ($locked->status !== LeaveStatus::Approved || $lastDayOn < $locked->starts_on->toDateString() || $lastDayOn >= $locked->ends_on->toDateString()) {
                throw $this->invalid('last_day_on', 'invalid_last_day');
            }
            $after = $locked->days()->whereDate('leave_date', '>', $lastDayOn);
            $returned = round((float) (clone $after)->where('is_counted', true)->sum('portion'), 1);
            $after->delete();

            $type = $locked->leaveType()->firstOrFail();
            if ($type->uses_balance && $returned > 0) {
                $this->ledger->giveBack($locked, $returned, (int) $actor->getKey(), 'Leave ended early.');
            }
            $before = ['ends_on' => $locked->ends_on->toDateString(), 'days' => (float) $locked->days_counted];
            $locked->forceFill(['ends_on' => $lastDayOn, 'ended_early_on' => $lastDayOn, 'days_counted' => max(0, round((float) $locked->days_counted - $returned, 1))])->save();
            $this->event($locked, 'ended_early', $actor, $reason, ['returned_days' => $returned]);
            $this->audit->execute(AuditEventType::LeaveEndedEarly, $actor, $locked, $locked->organization_id, $before, ['ends_on' => $lastDayOn, 'days' => (float) $locked->days_counted, 'returned_days' => $returned], $reason);

            return $locked;
        });

        $this->notifier->toEmployee($request, 'ended_early');

        return $request;
    }

    /**
     * Record the day of delivery on an approved maternity leave. The leave
     * then runs the post-natal days from that day. Working days between the
     * expected date and a later delivery are taken from annual leave, as the
     * law provides.
     */
    public function recordDelivery(User $actor, LeaveRequest $request, string $deliveredOn): LeaveRequest
    {
        if (! $this->access->canManage($actor, $request)) {
            throw new AuthorizationException;
        }
        $deliveredOn = Carbon::parse($deliveredOn)->toDateString();

        return DB::transaction(function () use ($actor, $request, $deliveredOn): LeaveRequest {
            $this->lockEmployee(Employee::query()->findOrFail($request->employee_id));
            $locked = $this->lock($request);
            $type = $locked->leaveType()->firstOrFail();
            $postnatal = $this->ruleOf($locked, $type)?->setting('postnatal_days');
            if ($locked->status !== LeaveStatus::Approved || $type->category !== LeaveCategory::Maternity || $locked->actual_delivery_on !== null || $postnatal === null) {
                throw $this->invalid('delivered_on', 'delivery_not_recordable');
            }
            if ($deliveredOn < $locked->starts_on->toDateString()) {
                throw $this->invalid('delivered_on', 'delivery_before_leave');
            }

            $endsOn = $this->calendar->addDays($deliveredOn, $postnatal - 1);
            $rows = $this->counter->days($type, $locked->starts_on->toDateString(), $endsOn);
            $locked->days()->delete();
            $this->writeDays($locked, $rows, approved: true);

            $overrun = 0;
            $expected = $locked->expected_delivery_on?->toDateString();
            if ($expected !== null && $deliveredOn > $expected) {
                $overrun = $this->calendar->workingDaysBetween($expected, $this->calendar->addDays($deliveredOn, -1));
                $annual = LeaveType::query()->active()->where('category', LeaveCategory::Annual->value)->orderBy('sort_order')->first();
                if ($overrun > 0 && $annual !== null) {
                    // May leave the year's balance below zero: the law then takes the days from next year's leave.
                    $this->ledger->write($locked->employee_id, $annual->id, $this->calendar->budgetYearOf($deliveredOn), LeaveEntryType::MaternityOverrun, -$overrun, [
                        'leave_request_id' => $locked->id, 'organization_id' => $locked->organization_id, 'created_by' => $actor->getKey(),
                        'unique_key' => "maternity-overrun:{$locked->id}",
                    ]);
                }
            }

            $before = ['ends_on' => $locked->ends_on->toDateString()];
            $locked->forceFill(['actual_delivery_on' => $deliveredOn, 'ends_on' => $endsOn, 'days_counted' => $this->counter->total($rows)])->save();
            $this->event($locked, 'delivery_recorded', $actor, null, ['delivered_on' => $deliveredOn, 'annual_days_taken' => $overrun]);
            $this->audit->execute(AuditEventType::LeaveDeliveryRecorded, $actor, $locked, $locked->organization_id, $before, ['ends_on' => $endsOn, 'annual_days_taken' => $overrun]);

            return $locked;
        });
    }

    /**
     * Record an approved period of the leave schedule as leave (owner rule:
     * a schedule that was approved and not changed before its first day is
     * counted by the system). The approval of the schedule is the decision,
     * so the leave is approved at once and the days are taken now. The
     * usual rules still hold: an overlap, a missing rule or too few days
     * left refuse it, and the caller keeps the reason on the period.
     */
    public function startFromPlan(LeavePlan $plan): LeaveRequest
    {
        $employee = Employee::query()->findOrFail($plan->employee_id);
        $type = LeaveType::query()->active()->find($plan->leave_type_id);
        if ($type === null) {
            throw $this->invalid('leave_type_id', 'type_unavailable');
        }
        if ($employee->status !== EmployeeStatus::Active) {
            throw $this->invalid('employee', 'employee_not_active');
        }
        $startsOn = $plan->starts_on->toDateString();
        $endsOn = $plan->ends_on->toDateString();
        $rule = $this->rules->ruleFor($type, $startsOn);
        if ($rule === null) {
            throw $this->invalid('leave_type_id', 'no_rule');
        }
        $rows = $this->counter->days($type, $startsOn, $endsOn);
        $total = $this->counter->total($rows);
        if ($total <= 0) {
            throw $this->invalid('starts_on', 'no_leave_days');
        }
        $budgetYear = $this->calendar->budgetYearOf($startsOn);
        $assignment = $this->context->assignmentOn($employee, Carbon::parse($this->calendar->today()));

        $request = DB::transaction(function () use ($plan, $employee, $assignment, $type, $rule, $startsOn, $endsOn, $rows, $total, $budgetYear): LeaveRequest {
            $this->lockEmployee($employee);
            $locked = LeavePlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->leave_request_id !== null || $locked->status !== LeavePlan::STATUS_APPROVED) {
                throw $this->invalid('plan', 'plan_already_requested');
            }
            $this->grants->settle($employee);
            $this->assertNoOverlap($employee->id, $startsOn, $endsOn);
            $this->assertWithinLimits($employee, $type, $rule, $total, $startsOn, $budgetYear);

            $request = new LeaveRequest;
            $request->fill(['reason' => $locked->note]);
            $request->forceFill([
                'reference_number' => $this->reference($budgetYear),
                'employee_id' => $employee->id,
                'requester_user_id' => null,
                'organization_id' => $assignment?->organization_id ?? $locked->organization_id,
                'organization_unit_id' => $assignment?->organization_unit_id ?? $locked->organization_unit_id,
                'position_id' => $assignment?->position_id,
                'leave_type_id' => $type->id,
                'leave_rule_set_id' => $rule->leave_rule_set_id,
                'budget_year' => $budgetYear,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'is_half_day' => false,
                'days_counted' => $total,
                'status' => LeaveStatus::Approved,
                'registered_by_hr' => false,
                // The person who approved the schedule is the one who decided this leave.
                'supervisor_decided_by' => $locked->decided_by,
                'supervisor_decided_at' => $locked->decided_at,
                'submitted_at' => now(),
                'hr_decided_at' => now(),
            ])->save();

            $this->writeDays($request, $rows, approved: true);
            if ($type->uses_balance) {
                $this->ledger->take($request, $type, $rule, null);
            }
            $locked->forceFill(['leave_request_id' => $request->id, 'auto_error' => null])->save();
            $this->event($request, 'counted_from_schedule', null, null, ['days' => $total]);
            $this->audit->execute(AuditEventType::LeaveApproved, null, $request, $request->organization_id, null, [
                'reference_number' => $request->reference_number, 'leave_type' => $type->code, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
                'days' => $total, 'status' => $request->status->value, 'source' => 'leave_schedule',
            ]);

            return $request;
        });

        $this->notifier->toEmployee($request, 'schedule_counted');

        return $request;
    }

    /** Whether evidence must be on file before HR may confirm. */
    public function needsDocument(LeaveType $type, ?LeaveRule $rule, float $days): bool
    {
        if ($type->requires_document) {
            return true;
        }
        $after = $type->category === LeaveCategory::Sick ? $rule?->setting('certificate_from_days') : null;

        return $after !== null && $days >= $after;
    }

    public function addDocument(User $actor, LeaveRequest $request, string $path, string $originalName, ?string $mime, int $size): LeaveDocument
    {
        $document = $request->documents()->create([
            'kind' => 'evidence', 'original_name' => $originalName, 'disk' => 'local', 'path' => $path,
            'mime_type' => $mime, 'size_bytes' => $size, 'uploaded_by' => $actor->getKey(),
        ]);
        $this->event($request, 'document_added', $actor, null, ['name' => $originalName]);

        return $document;
    }

    /**
     * The planned period of a maternity leave: from the day she starts (not
     * earlier than the pre-natal days before the expected date) to the last
     * post-natal day counted from the expected date. Without an expected
     * date (a pregnancy that ended early) the employee enters the period,
     * within the days the rules give for it.
     *
     * @return array{0: string, 1: string}
     */
    private function maternityPeriod(LeaveRule $rule, string $startsOn, string $endsOn, ?string $expectedDelivery): array
    {
        $prenatal = $rule->setting('prenatal_days');
        $postnatal = $rule->setting('postnatal_days');
        if ($expectedDelivery === null) {
            $limit = $rule->setting('miscarriage_days');
            if ($limit !== null && count($this->calendar->datesBetween($startsOn, $endsOn)) > $limit) {
                throw $this->invalid('ends_on', 'exceeds_period_limit', ['max' => $limit]);
            }

            return [$startsOn, $endsOn];
        }
        if ($prenatal === null || $postnatal === null) {
            throw $this->invalid('leave_type_id', 'no_rule');
        }
        if ($startsOn < $this->calendar->addDays($expectedDelivery, -$prenatal) || $startsOn > $expectedDelivery) {
            throw $this->invalid('starts_on', 'maternity_start_out_of_range');
        }

        return [$startsOn, $this->calendar->addDays($expectedDelivery, $postnatal - 1)];
    }

    private function assertNoOverlap(string $employeeId, string $startsOn, string $endsOn): void
    {
        $overlaps = LeaveRequestDay::query()
            ->where('employee_id', $employeeId)
            ->whereDate('leave_date', '>=', $startsOn)->whereDate('leave_date', '<=', $endsOn)
            ->exists();
        if ($overlaps) {
            throw $this->invalid('starts_on', 'overlaps');
        }
    }

    /**
     * The limits of the rule for this request. Returns the pay breakdown of
     * a sick leave (days at full and at half pay), else null.
     *
     * @return array{full: float, half: float}|null
     */
    private function assertWithinLimits(Employee $employee, LeaveType $type, LeaveRule $rule, float $total, string $startsOn, int $budgetYear): ?array
    {
        if ($rule->max_days_per_request !== null && $total > (float) $rule->max_days_per_request) {
            throw $this->invalid('ends_on', 'exceeds_request_limit', ['max' => $this->number((float) $rule->max_days_per_request)]);
        }

        if ($type->uses_balance) {
            $free = $this->ledger->available($employee->id, $type, $rule, $budgetYear) - $this->ledger->pendingDays($employee->id, $type->id);
            if ($total - $free > 0.001) {
                throw $this->invalid('ends_on', 'insufficient_balance_detail', ['available' => $this->number(max(0, $free)), 'asked' => $this->number($total)]);
            }

            return null;
        }

        if ($rule->max_days_per_year !== null) {
            $used = (float) LeaveRequest::query()
                ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('budget_year', $budgetYear)
                ->whereIn('status', LeaveStatus::values(LeaveStatus::HOLDING))->sum('days_counted');
            if ($used + $total - (float) $rule->max_days_per_year > 0.001) {
                throw $this->invalid('ends_on', 'exceeds_year_limit', ['max' => $this->number((float) $rule->max_days_per_year), 'used' => $this->number($used)]);
            }
        }

        return $type->category === LeaveCategory::Sick ? $this->sickLeavePay($employee, $rule, $total, $startsOn) : null;
    }

    /**
     * Sick leave is limited inside two windows that end on the first day of
     * the new leave (one year, and four years), and paid in full for the
     * first months of the year and at half pay for the rest. A month is
     * thirty days. A figure the institution has not set is not enforced.
     *
     * @return array{full: float, half: float}
     */
    private function sickLeavePay(Employee $employee, LeaveRule $rule, float $total, string $startsOn): array
    {
        $usedSince = fn (string $from): float => (float) LeaveRequestDay::query()
            ->where('employee_id', $employee->id)->where('is_counted', true)
            ->whereDate('leave_date', '>=', $from)->whereDate('leave_date', '<', $startsOn)
            ->whereHas('request.leaveType', fn ($query) => $query->where('category', LeaveCategory::Sick->value))
            ->sum('portion');

        $windowMonths = $rule->setting('window_months') ?? 12;
        $usedInYear = $usedSince($this->calendar->addMonths($startsOn, -$windowMonths));

        $start = $employee->service_start_date?->toDateString();
        $probationMonths = $rule->setting('probation_months');
        $onProbation = $probationMonths !== null && $start !== null && $this->calendar->addMonths($start, $probationMonths) > $startsOn;
        $maxMonths = $onProbation ? $rule->setting('probation_max_months') : $rule->setting('max_months_in_window');
        if ($maxMonths !== null && $usedInYear + $total > $maxMonths * self::DAYS_PER_MONTH) {
            throw $this->invalid('ends_on', $onProbation ? 'sick_probation_limit' : 'sick_year_limit', ['months' => $maxMonths]);
        }

        $longYears = $rule->setting('long_window_years');
        $longMax = $rule->setting('max_months_in_long_window');
        if (! $onProbation && $longYears !== null && $longMax !== null
            && $usedSince($this->calendar->addMonths($startsOn, -12 * $longYears)) + $total > $longMax * self::DAYS_PER_MONTH) {
            throw $this->invalid('ends_on', 'sick_long_limit', ['months' => $longMax, 'years' => $longYears]);
        }

        $fullMonths = $rule->setting('full_pay_months');
        if ($fullMonths === null) {
            return ['full' => $total, 'half' => 0.0];
        }
        $full = max(0.0, min($total, $fullMonths * self::DAYS_PER_MONTH - $usedInYear));

        return ['full' => round($full, 1), 'half' => round($total - $full, 1)];
    }

    /** The rule the request was counted under; a request never moves to a newer law. */
    private function ruleOf(LeaveRequest $request, LeaveType $type): ?LeaveRule
    {
        return LeaveRule::query()->where('leave_rule_set_id', $request->leave_rule_set_id)->where('leave_type_id', $type->id)->first()
            ?? $this->rules->ruleFor($type, $request->starts_on->toDateString());
    }

    /** @param list<array{leave_date: string, portion: float, is_full_day: bool, is_counted: bool}> $rows */
    private function writeDays(LeaveRequest $request, array $rows, bool $approved): void
    {
        foreach ($rows as $row) {
            $request->days()->create([...$row, 'employee_id' => $request->employee_id, 'is_approved' => $approved]);
        }
    }

    /** @param array<string, mixed> $data */
    private function event(LeaveRequest $request, string $action, ?User $actor, ?string $comment, array $data = []): void
    {
        LeaveRequestEvent::query()->create([
            'leave_request_id' => $request->id, 'action' => $action, 'actor_user_id' => $actor?->getKey(), 'actor_name' => $actor?->name,
            'comment' => $comment, 'data' => $data === [] ? null : $data, 'created_at' => now(),
        ]);
    }

    private function lock(LeaveRequest $request): LeaveRequest
    {
        return LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockEmployee(Employee $employee): Employee
    {
        return Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
    }

    private function reference(int $budgetYear): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $reference = sprintf('LV-%d-%06d', $budgetYear, random_int(0, 999999));
            if (! LeaveRequest::query()->where('reference_number', $reference)->exists()) {
                return $reference;
            }
        }

        throw new \RuntimeException('No free leave reference number.');
    }

    /** @param array<string, string|int|float> $params */
    private function invalid(string $field, string $key, array $params = []): ValidationException
    {
        return ValidationException::withMessages([$field => __("leave::messages.errors.{$key}", $params)]);
    }

    private function number(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');
    }
}
