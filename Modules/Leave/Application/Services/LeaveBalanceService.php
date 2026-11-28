<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Domain\Enums\LeaveEntryType;
use Modules\Leave\Domain\Models\LeaveLedgerEntry;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveRule;
use Modules\Leave\Domain\Models\LeaveType;

/**
 * What an employee has, has asked for and has taken, per leave type; and the
 * three ways a person changes a balance: an opening balance, a correction,
 * and a postponement by the head of the institution. Each is one ledger
 * line with its reason, and each is audited.
 */
class LeaveBalanceService
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly LeaveRules $rules,
        private readonly LeaveLedger $ledger,
        private readonly LeaveGrantService $grants,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * One row per active leave type, for the budget year of today.
     *
     * @return array{budget_year: int, year_starts_on: string, year_ends_on: string, rules_confirmed: bool, has_rules: bool, types: list<array<string, mixed>>}
     */
    public function summary(Employee $employee): array
    {
        $today = $this->calendar->today();
        $budgetYear = $this->calendar->budgetYearOf($today);
        $this->grants->settle($employee, $today);
        $ruleSet = $this->rules->ruleSetOn($today);

        $held = LeaveRequest::query()
            ->where('employee_id', $employee->id)->where('budget_year', $budgetYear)
            ->whereIn('status', LeaveStatus::values(LeaveStatus::HOLDING))
            ->groupBy('leave_type_id', 'status')
            ->selectRaw('leave_type_id, status, SUM(days_counted) as days')->get();
        $daysIn = static fn (string $typeId, array $statuses): float => round((float) $held->where('leave_type_id', $typeId)
            ->filter(fn ($row): bool => in_array($row->status instanceof LeaveStatus ? $row->status : LeaveStatus::from((string) $row->status), $statuses, true))->sum('days'), 1);

        $types = [];
        foreach (LeaveType::query()->active()->orderBy('sort_order')->orderBy('name_en')->get() as $type) {
            if (! $type->openTo($employee->gender)) {
                continue;
            }
            $rule = $this->rules->ruleFor($type, $today);
            $row = [
                'id' => $type->id, 'code' => $type->code, 'name_en' => $type->name_en, 'name_am' => $type->name_am,
                'category' => $type->category->value, 'day_unit' => $type->day_unit, 'pay_class' => $type->pay_class,
                'uses_balance' => $type->uses_balance, 'allows_half_day' => $type->allows_half_day,
                'requires_document' => $type->requires_document, 'employee_can_request' => $type->employee_can_request,
                'has_rule' => $rule !== null,
                'taken' => $daysIn($type->id, [LeaveStatus::Approved]),
                'pending' => $daysIn($type->id, LeaveStatus::PENDING),
                'max_days_per_request' => $rule?->max_days_per_request,
                'max_days_per_year' => $rule?->max_days_per_year,
                'settings' => $rule?->settings,
            ];
            if ($type->uses_balance) {
                $row += $this->balanceFigures($employee, $type, $rule, $budgetYear, $today);
            }
            $types[] = $row;
        }

        return [
            'budget_year' => $budgetYear,
            'year_starts_on' => $this->calendar->budgetYearStart($budgetYear),
            'year_ends_on' => $this->calendar->budgetYearEnd($budgetYear),
            'has_rules' => $ruleSet !== null,
            'rules_confirmed' => (bool) $ruleSet?->is_confirmed,
            'types' => $types,
        ];
    }

    /**
     * The ledger of an employee, newest line first.
     *
     * @return list<array<string, mixed>>
     */
    public function ledgerOf(Employee $employee, int $limit = 300): array
    {
        return LeaveLedgerEntry::query()
            ->where('employee_id', $employee->id)
            ->with(['leaveType:id,code,name_en,name_am', 'author:id,name', 'request:id,reference_number'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (LeaveLedgerEntry $entry): array => [
                'id' => $entry->id,
                'leave_type' => ['name_en' => $entry->leaveType?->name_en, 'name_am' => $entry->leaveType?->name_am],
                'entitlement_year' => $entry->entitlement_year,
                'entry_type' => $entry->entry_type->value,
                'days' => $entry->days,
                'usable_until_year' => $entry->usable_until_year,
                'note' => $entry->note,
                'reference_number' => $entry->request?->reference_number,
                'leave_request_id' => $entry->leave_request_id,
                'author' => $entry->author?->name,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->all();
    }

    /** An opening balance or a correction: one line, with its reason. */
    public function adjust(User $actor, Employee $employee, LeaveType $type, int $entitlementYear, float $days, string $reason, bool $opening, ?string $organizationId): void
    {
        if (! $type->uses_balance) {
            throw ValidationException::withMessages(['leave_type_id' => __('leave::messages.errors.type_has_no_balance')]);
        }
        if (abs($days) < 0.5) {
            throw ValidationException::withMessages(['days' => __('leave::messages.errors.days_required')]);
        }
        $this->ledger->write($employee->id, $type->id, $entitlementYear, $opening ? LeaveEntryType::Opening : LeaveEntryType::Adjustment, $days, [
            'note' => $reason, 'created_by' => $actor->getKey(), 'organization_id' => $organizationId,
        ]);
        $this->audit->execute(AuditEventType::LeaveBalanceAdjusted, $actor, $employee, $organizationId, null, [
            'leave_type' => $type->code, 'entitlement_year' => $entitlementYear, 'days' => $days, 'kind' => $opening ? 'opening' : 'adjustment',
        ], $reason);
    }

    /**
     * The head of the institution lets the unused days of a budget year be
     * used up to a later year, within the years the rule allows.
     */
    public function postpone(User $actor, Employee $employee, LeaveType $type, int $entitlementYear, int $untilYear, string $reason, ?string $organizationId): void
    {
        $rule = $this->rules->ruleFor($type, $this->calendar->today());
        if ($rule === null || $rule->carry_over_mode !== LeaveRule::CARRY_AUTHORIZED) {
            throw ValidationException::withMessages(['leave_type_id' => __('leave::messages.errors.postponement_not_applicable')]);
        }
        $last = $entitlementYear + max(0, (int) $rule->carry_over_years);
        if ($untilYear <= $entitlementYear || $untilYear > $last) {
            throw ValidationException::withMessages(['until_year' => __('leave::messages.errors.postponement_out_of_range', ['last' => $last])]);
        }
        $bucket = $this->ledger->buckets($employee->id, $type->id)[$entitlementYear] ?? null;
        if ($bucket === null || $bucket['balance'] <= 0) {
            throw ValidationException::withMessages(['entitlement_year' => __('leave::messages.errors.nothing_to_postpone')]);
        }

        $this->ledger->write($employee->id, $type->id, $entitlementYear, LeaveEntryType::Postponement, 0, [
            'usable_until_year' => $untilYear, 'note' => $reason, 'created_by' => $actor->getKey(), 'organization_id' => $organizationId,
        ]);
        $this->audit->execute(AuditEventType::LeavePostponed, $actor, $employee, $organizationId, null, [
            'leave_type' => $type->code, 'entitlement_year' => $entitlementYear, 'usable_until_year' => $untilYear, 'days' => $bucket['balance'],
        ], $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceFigures(Employee $employee, LeaveType $type, ?LeaveRule $rule, int $budgetYear, string $today): array
    {
        $buckets = $this->ledger->buckets($employee->id, $type->id);
        $usable = $this->ledger->usableBuckets($employee->id, $type, $rule, $budgetYear);
        $usableYears = array_column($usable, 'year');
        $available = round((float) array_sum(array_column($usable, 'balance')), 1);
        $pending = $this->ledger->pendingDays($employee->id, $type->id);

        // Days of earlier years that wait for the head of the institution's decision.
        $waiting = [];
        foreach ($buckets as $bucket) {
            if ($bucket['year'] < $budgetYear && $bucket['balance'] > 0 && ! in_array($bucket['year'], $usableYears, true)) {
                $waiting[] = ['year' => $bucket['year'], 'days' => $bucket['balance'], 'last_year' => $this->rules->lastChanceYear($rule, $bucket['year'], $bucket['postponed_until'])];
            }
        }

        $granted = round((float) LeaveLedgerEntry::query()
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('entitlement_year', $budgetYear)
            ->whereIn('entry_type', [LeaveEntryType::Grant->value, LeaveEntryType::Opening->value])->sum('days'), 1);
        $reason = null;
        if ($rule !== null && $granted <= 0) {
            $reason = $this->grants->entitlement($employee, $type, $rule, $budgetYear, $today)['reason'];
        }

        return [
            'granted' => $granted,
            'carried' => round((float) array_sum(array_map(static fn (array $b): float => $b['year'] < $budgetYear ? $b['balance'] : 0.0, $usable)), 1),
            'available' => $available,
            'free' => round($available - $pending, 1),
            'buckets' => array_values($usable),
            'waiting' => $waiting,
            'carry_over_mode' => $rule?->carry_over_mode,
            'carry_over_years' => $rule?->carry_over_years,
            'no_grant_reason' => $reason,
        ];
    }
}
