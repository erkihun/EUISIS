<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Domain\Enums\LeaveEntryType;
use Modules\Leave\Domain\Models\LeaveLedgerEntry;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveRule;
use Modules\Leave\Domain\Models\LeaveType;

/**
 * The leave ledger (ADR 0039, decision 2). A balance is the sum of an
 * employee's lines for a leave type and entitlement year ("bucket"). Days
 * are taken from the oldest bucket that may still be used, and given back to
 * the buckets they were taken from.
 *
 * Callers that spend days hold a lock on the employee row for the length of
 * their transaction, so two approvals cannot spend the same days.
 */
class LeaveLedger
{
    public function __construct(private readonly LeaveRules $rules) {}

    /**
     * Every bucket of an employee for one type, oldest first.
     *
     * @return array<int, array{year: int, balance: float, postponed_until: ?int}>
     */
    public function buckets(string $employeeId, string $leaveTypeId): array
    {
        $rows = LeaveLedgerEntry::query()
            ->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
            ->groupBy('entitlement_year')->orderBy('entitlement_year')
            ->get([
                'entitlement_year',
                DB::raw('SUM(days) as balance'),
                DB::raw('MAX(usable_until_year) as postponed_until'),
            ]);

        $buckets = [];
        foreach ($rows as $row) {
            $year = (int) $row->entitlement_year;
            $buckets[$year] = ['year' => $year, 'balance' => round((float) $row->balance, 1), 'postponed_until' => $row->postponed_until === null ? null : (int) $row->postponed_until];
        }

        return $buckets;
    }

    /**
     * The buckets that may be spent on a leave starting in $budgetYear, oldest first.
     *
     * @return list<array{year: int, balance: float, usable_until: int}>
     */
    public function usableBuckets(string $employeeId, LeaveType $type, ?LeaveRule $rule, int $budgetYear): array
    {
        $usable = [];
        foreach ($this->buckets($employeeId, $type->id) as $bucket) {
            $until = $this->rules->usableUntil($rule, $bucket['year'], $bucket['postponed_until']);
            if ($bucket['year'] <= $budgetYear && $until >= $budgetYear) {
                $usable[] = ['year' => $bucket['year'], 'balance' => $bucket['balance'], 'usable_until' => $until];
            }
        }

        return $usable;
    }

    public function available(string $employeeId, LeaveType $type, ?LeaveRule $rule, int $budgetYear): float
    {
        return round((float) array_sum(array_column($this->usableBuckets($employeeId, $type, $rule, $budgetYear), 'balance')), 1);
    }

    /** Days asked for and not yet decided: they are not spent, but they are spoken for. */
    public function pendingDays(string $employeeId, string $leaveTypeId, ?string $exceptRequestId = null): float
    {
        return round((float) LeaveRequest::query()
            ->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', LeaveStatus::values(LeaveStatus::PENDING))
            ->when($exceptRequestId !== null, fn ($query) => $query->whereKeyNot($exceptRequestId))
            ->sum('days_counted'), 1);
    }

    /**
     * Take the days of an approved request, oldest usable bucket first.
     *
     * @throws ValidationException when the usable buckets do not hold enough days
     */
    public function take(LeaveRequest $request, LeaveType $type, ?LeaveRule $rule, ?int $actorUserId): void
    {
        $left = (float) $request->days_counted;
        $buckets = $this->usableBuckets($request->employee_id, $type, $rule, (int) $request->budget_year);
        if ($left - (float) array_sum(array_column($buckets, 'balance')) > 0.001) {
            throw ValidationException::withMessages(['days' => __('leave::messages.errors.insufficient_balance')]);
        }

        foreach ($buckets as $bucket) {
            if ($left <= 0.001) {
                break;
            }
            $portion = min($left, max(0.0, $bucket['balance']));
            if ($portion <= 0.001) {
                continue;
            }
            $this->write($request->employee_id, $type->id, $bucket['year'], LeaveEntryType::Taken, -$portion, [
                'leave_request_id' => $request->id, 'leave_rule_set_id' => $request->leave_rule_set_id,
                'organization_id' => $request->organization_id, 'created_by' => $actorUserId,
            ]);
            $left -= $portion;
        }
    }

    /**
     * Give days of a request back, newest bucket first, never more than was
     * taken from a bucket and not already returned.
     */
    public function giveBack(LeaveRequest $request, float $days, ?int $actorUserId, ?string $note = null): void
    {
        $perYear = [];
        foreach (LeaveLedgerEntry::query()->where('leave_request_id', $request->id)->whereIn('entry_type', [LeaveEntryType::Taken->value, LeaveEntryType::Returned->value])->get() as $entry) {
            $perYear[$entry->entitlement_year] = ($perYear[$entry->entitlement_year] ?? 0.0) + (float) $entry->days;
        }
        krsort($perYear);

        $left = $days;
        foreach ($perYear as $year => $net) {
            if ($left <= 0.001) {
                break;
            }
            // $net is negative while days of this bucket are still out.
            $portion = min($left, max(0.0, -$net));
            if ($portion <= 0.001) {
                continue;
            }
            $this->write($request->employee_id, $request->leave_type_id, (int) $year, LeaveEntryType::Returned, $portion, [
                'leave_request_id' => $request->id, 'organization_id' => $request->organization_id, 'created_by' => $actorUserId, 'note' => $note,
            ]);
            $left -= $portion;
        }
    }

    /**
     * Append one line. With a unique key the line is written once, however
     * often the caller runs (the yearly grant, the expiry of old days).
     *
     * @param  array<string, mixed>  $extra
     */
    public function write(string $employeeId, string $leaveTypeId, int $entitlementYear, LeaveEntryType $type, float $days, array $extra = []): ?LeaveLedgerEntry
    {
        $attributes = [
            'employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'entitlement_year' => $entitlementYear,
            'entry_type' => $type->value, 'days' => round($days, 1), 'created_at' => now(), ...$extra,
        ];
        $key = $attributes['unique_key'] ?? null;
        if ($key === null) {
            return LeaveLedgerEntry::query()->create($attributes);
        }
        if (LeaveLedgerEntry::query()->where('unique_key', $key)->exists()) {
            return null;
        }

        try {
            // A savepoint, so a duplicate written by a concurrent run does not abort the caller's transaction (PostgreSQL).
            return DB::transaction(fn (): LeaveLedgerEntry => LeaveLedgerEntry::query()->create($attributes));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
