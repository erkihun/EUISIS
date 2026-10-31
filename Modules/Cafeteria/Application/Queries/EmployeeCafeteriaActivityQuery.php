<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Queries;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Cafeteria\Application\Services\CafeteriaAvailableSubsidyService;
use Modules\Cafeteria\Application\Services\CafeteriaLedgerService;
use Modules\Cafeteria\Application\Services\CafeteriaSubsidyRuleResolver;
use Modules\Cafeteria\Application\Services\Policy\CafeteriaPolicyResolver;
use Modules\Cafeteria\Application\Services\WorkingDayCalendarService;
use Modules\Cafeteria\Contracts\EmployeeCafeteriaActivity;
use Modules\Cafeteria\Domain\Models\CafeteriaTransaction;
use Modules\Cafeteria\Domain\Models\CafeteriaTransactionConsumedDay;
use Modules\Cafeteria\Domain\Models\OrganizationCafeteriaAccess;
use Modules\Cafeteria\Infrastructure\Platform\CafeteriaPlatformClient;

/**
 * My Portal's cafeteria card, moved here from EmployeePortalController
 * unchanged: from the cafeteria platform's API when it serves the portal
 * (ADR 0014), otherwise from the local ledger, rules and consumed days.
 */
final class EmployeeCafeteriaActivityQuery implements EmployeeCafeteriaActivity
{
    public function __construct(
        private readonly CafeteriaLedgerService $ledger,
        private readonly CafeteriaSubsidyRuleResolver $resolver,
        private readonly CafeteriaAvailableSubsidyService $available,
        private readonly WorkingDayCalendarService $workingDays,
        private readonly CafeteriaPolicyResolver $policies,
        private readonly CafeteriaPlatformClient $platform,
    ) {}

    public function forEmployee(string $employeeId, CarbonInterface $today): array
    {
        $employee = Employee::query()->with('currentAssignment')->findOrFail($employeeId);
        $today = Carbon::instance($today);

        return $this->platform->servesEmployeePortal()
            ? $this->fromPlatform($employee, $today)
            : $this->fromLocalRecords($employee, $today);
    }

    /**
     * The daily subsidy under the cafeteria service policy that the scan
     * terminal itself applies. Organizations configured only through service
     * policies (no legacy subsidy rule) otherwise showed no daily rate.
     * Resolved at the organization's primary cafeteria, with the same
     * resolver a scan uses, so the portal never shows a rate a scan would not
     * charge.
     */
    private function policyDailySubsidy(Employee $employee, Carbon $today): ?float
    {
        $organizationId = $employee->currentAssignment?->organization_id;
        if ($organizationId === null) {
            return null;
        }

        $cafeteria = OrganizationCafeteriaAccess::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('primary_cafeteria_id')
            ->effectiveOn($today)
            ->with('primaryCafeteria')
            ->first()?->primaryCafeteria;
        if ($cafeteria === null) {
            return null;
        }

        $resolution = $this->policies->resolve($employee, $cafeteria, $today);

        return $resolution->resolved() ? (float) $resolution->policy->daily_subsidy_amount : null;
    }

    /**
     * The same activity block, from the cafeteria platform's API (ADR 0014):
     * the employee's standing (policy, exclusion, today's use) and their
     * accepted meals, laid out over the EUISIS working-day calendar.
     *
     * @return array<string, mixed>
     */
    private function fromPlatform(Employee $employee, Carbon $today): array
    {
        $platform = $this->platform;
        $workingDays = $this->workingDays;
        $standing = $platform->entitlement((string) $employee->id) ?? [];
        $meals = $platform->transactions((string) $employee->id, 100);

        $weekMon = $today->copy()->startOfWeek(Carbon::MONDAY);
        $weekFri = $weekMon->copy()->addDays(4);
        $windowStart = $today->copy()->subMonths(13)->startOfMonth();
        $windowEnd = $today->copy()->addMonth()->endOfMonth();

        /** @var array<string, array{date: string, subsidy: float}> $consumedByDate */
        $consumedByDate = [];
        foreach ($meals as $meal) {
            $date = (string) ($meal['transaction_date'] ?? '');
            if ($date === '' || ($meal['status'] ?? null) !== 'accepted') {
                continue;
            }
            $consumedByDate[$date] = ['date' => $date, 'subsidy' => round(((float) ($consumedByDate[$date]['subsidy'] ?? 0)) + (float) ($meal['subsidy_amount'] ?? 0), 2)];
        }
        ksort($consumedByDate);
        $within = fn (string $from, string $to): array => array_filter($consumedByDate, fn (array $r): bool => $r['date'] >= $from && $r['date'] <= $to);

        $holidayDates = $workingDays->holidaysBetween($windowStart, $windowEnd)
            ->map(fn ($h) => Carbon::parse($h->holiday_date ?? $h->date ?? $h)->toDateString())->values()->all();

        $todayStr = $today->toDateString();
        $policy = is_array($standing['policy'] ?? null) ? $standing['policy'] : null;
        $dailyRate = $policy === null ? null : (float) ($policy['daily_subsidy_amount'] ?? 0);
        $weekConsumed = $within($weekMon->toDateString(), $weekFri->toDateString());
        $weekDays = $workingDays->workingDaysBetween($weekMon, $weekFri);
        $monthConsumed = $within($today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString());
        $yearConsumed = $within($today->copy()->startOfYear()->toDateString(), $windowEnd->toDateString());

        return [
            'type' => 'cafeteria',
            'daily' => ['date' => $todayStr, 'consumed' => isset($consumedByDate[$todayStr]), 'subsidy' => $consumedByDate[$todayStr]['subsidy'] ?? 0.0],
            'weekly' => [
                'week_start' => $weekMon->toDateString(),
                'week_end' => $weekFri->toDateString(),
                'days_consumed' => count($weekConsumed),
                'days_total' => $weekDays,
                'days_remaining' => max(0, $weekDays - count($weekConsumed)),
                'subsidy_used' => round(array_sum(array_column($weekConsumed, 'subsidy')), 2),
                'subsidy_remaining' => $dailyRate === null ? null : round(max(0, $weekDays - count($weekConsumed)) * $dailyRate, 2),
                'daily_rate' => $dailyRate,
                'balance' => 0.0,
                'pending' => 0.0,
            ],
            'monthly' => [
                'label' => $today->format('F Y'), 'month' => (int) $today->format('n'), 'year' => (int) $today->format('Y'),
                'days_consumed' => count($monthConsumed),
                'working_days' => $workingDays->workingDaysBetween($today->copy()->startOfMonth(), $today->copy()->endOfMonth()),
                'subsidy_used' => round(array_sum(array_column($monthConsumed, 'subsidy')), 2),
            ],
            'yearly' => ['year' => (int) $today->format('Y'), 'days_consumed' => count($yearConsumed), 'subsidy_used' => round(array_sum(array_column($yearConsumed, 'subsidy')), 2)],
            'employee' => ['id' => $employee->id, 'name' => $employee->full_name, 'number' => $employee->employee_number],
            'today' => $todayStr,
            'consumed' => array_map(fn (array $r): float => $r['subsidy'], $consumedByDate) ?: (object) [],
            'holidays' => $holidayDates,
            'window_start' => $windowStart->toDateString(),
            'window_end' => $windowEnd->toDateString(),
            'transactions' => array_values(array_map(fn (array $meal): array => [
                'date' => $meal['transaction_date'] ?? null,
                'time' => isset($meal['scanned_at']) ? Carbon::parse((string) $meal['scanned_at'])->format('H:i') : null,
                'subsidy' => (float) ($meal['subsidy_amount'] ?? 0),
                'meal_amount' => (float) ($meal['total_amount'] ?? 0),
                'employee_pays' => (float) ($meal['employee_payable_amount'] ?? 0),
                'provider' => $meal['cafeteria']['name_en'] ?? null,
                'status' => $meal['status'] ?? null,
            ], array_slice($meals, 0, 15))),
            // The platform's own view of the employee, for the page to show where it differs from the local rules.
            'platform' => ['eligible' => (bool) ($standing['eligible'] ?? false), 'reason' => $standing['reason'] ?? null, 'cafeteria' => $standing['cafeteria'] ?? null, 'url' => $platform->url()],
        ];
    }

    /** @return array<string, mixed> */
    private function fromLocalRecords(Employee $employee, Carbon $today): array
    {
        $ledger = $this->ledger;
        $resolver = $this->resolver;
        $available = $this->available;
        $workingDays = $this->workingDays;
        $rule = $resolver->resolve($employee, $today);
        $balance = $ledger->getBalance($employee);
        $pending = $ledger->getPendingDeduction($employee);
        $dailyAmount = $rule ? (float) $rule->subsidy_amount : $this->policyDailySubsidy($employee, $today);
        $availData = $rule ? $available->calculate($employee, $today, $rule) : null;

        // Week bounds (Mon–Fri)
        $weekMon = $today->copy()->startOfWeek(Carbon::MONDAY);
        $weekFri = $weekMon->copy()->addDays(4);

        // Wide window so the client can freely navigate months in either the
        // Gregorian or Ethiopian calendar (Ethiopian months straddle two
        // Gregorian months and the Ethiopian year is offset by ~8 years).
        $windowStart = $today->copy()->subMonths(13)->startOfMonth();
        $windowEnd = $today->copy()->addMonth()->endOfMonth();

        /** @var array<string, array{date:string,subsidy:float}> */
        $consumedByDate = [];

        CafeteriaTransactionConsumedDay::query()
            ->where('employee_id', $employee->id)
            ->whereNull('reversed_at')
            ->whereBetween('consumed_date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->orderBy('consumed_date')
            ->get(['consumed_date', 'subsidy_amount'])
            ->each(function ($r) use (&$consumedByDate): void {
                $ds = is_string($r->consumed_date)
                    ? $r->consumed_date
                    : Carbon::parse($r->consumed_date)->toDateString();
                $consumedByDate[$ds] = [
                    'date' => $ds,
                    'subsidy' => (float) ($r->subsidy_amount ?? 0),
                ];
            });

        // Holiday dates within the window (flat list of Gregorian ISO strings)
        $holidayDates = $workingDays->holidaysBetween($windowStart, $windowEnd)
            ->map(fn ($h) => Carbon::parse($h->holiday_date ?? $h->date ?? $h)->toDateString())
            ->values()
            ->all();

        // Also load yearly totals (may extend beyond the 3-month window)
        $yearStart = $today->copy()->startOfYear();
        $yearlyConsumed = CafeteriaTransactionConsumedDay::query()
            ->where('employee_id', $employee->id)
            ->whereNull('reversed_at')
            ->whereBetween('consumed_date', [$yearStart->toDateString(), $windowEnd->toDateString()])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(subsidy_amount), 0) as total')
            ->first();

        // ── Daily ─────────────────────────────────────────────────────────
        $todayStr = $today->toDateString();
        $todayRow = $consumedByDate[$todayStr] ?? null;
        $daily = [
            'date' => $todayStr,
            'consumed' => $todayRow !== null,
            'subsidy' => $todayRow['subsidy'] ?? 0.0,
        ];

        // ── Weekly ────────────────────────────────────────────────────────
        $weekStart = $weekMon->toDateString();
        $weekEnd = $weekFri->toDateString();
        $weekConsumed = array_filter($consumedByDate, fn ($r) => $r['date'] >= $weekStart && $r['date'] <= $weekEnd);
        $weekSubsidy = array_sum(array_column($weekConsumed, 'subsidy'));
        $weekDays = $workingDays->workingDaysBetween($weekMon, $weekFri);
        $weekly = [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'days_consumed' => count($weekConsumed),
            'days_total' => $weekDays,
            'days_remaining' => $availData['available_days_count'] ?? max(0, $weekDays - count($weekConsumed)),
            'subsidy_used' => round($weekSubsidy, 2),
            'subsidy_remaining' => $availData ? round((float) $availData['remaining'], 2) : null,
            'daily_rate' => $dailyAmount,
            'balance' => round($balance, 2),
            'pending' => round($pending, 2),
        ];

        // ── Monthly ───────────────────────────────────────────────────────
        $mStart = $today->copy()->startOfMonth()->toDateString();
        $mEnd = $today->copy()->endOfMonth()->toDateString();
        $monthConsumed = array_filter($consumedByDate, fn ($r) => $r['date'] >= $mStart && $r['date'] <= $mEnd);
        $monthSubsidy = array_sum(array_column($monthConsumed, 'subsidy'));
        $monthWorkDays = $workingDays->workingDaysBetween(
            Carbon::parse($mStart),
            Carbon::parse($mEnd),
        );
        $monthly = [
            'label' => $today->format('F Y'),
            'month' => (int) $today->format('n'),
            'year' => (int) $today->format('Y'),
            'days_consumed' => count($monthConsumed),
            'working_days' => $monthWorkDays,
            'subsidy_used' => round($monthSubsidy, 2),
        ];

        // ── Yearly ────────────────────────────────────────────────────────
        $yearly = [
            'year' => (int) $today->format('Y'),
            'days_consumed' => (int) ($yearlyConsumed?->cnt ?? 0),
            'subsidy_used' => round((float) ($yearlyConsumed?->total ?? 0), 2),
        ];

        // ── Flat day-metadata map (Gregorian ISO keyed) ───────────────────
        // The client builds the calendar grid in the active calendar system
        // (Ethiopian for `am`, Gregorian for `en`) and looks each day up here
        // by its Gregorian ISO date — exactly like the cafeteria scan page.
        $consumedMap = [];
        foreach ($consumedByDate as $ds => $row) {
            $consumedMap[$ds] = $row['subsidy'];
        }

        // ── Transactions ──────────────────────────────────────────────────
        $transactions = CafeteriaTransaction::query()
            ->where('employee_id', $employee->id)
            ->with('provider:id,name_en,name_am')
            ->orderByDesc('transaction_date')
            ->orderByDesc('transaction_time')
            ->limit(15)
            ->get()
            ->map(fn ($t) => [
                'date' => $t->transaction_date?->toDateString(),
                'time' => $this->formatNullableTime($t->transaction_time),
                'subsidy' => (float) ($t->subsidy_amount_applied ?? 0),
                'meal_amount' => (float) ($t->meal_amount ?? 0),
                'employee_pays' => (float) ($t->employee_payable_amount ?? 0),
                'provider' => $t->provider?->name_en,
                'status' => $t->status?->value,
            ])
            ->all();

        return [
            'type' => 'cafeteria',
            'daily' => $daily,
            'weekly' => $weekly,
            'monthly' => $monthly,
            'yearly' => $yearly,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
            ],
            'today' => $todayStr,
            'consumed' => $consumedMap,                 // { "YYYY-MM-DD": subsidyFloat }
            'holidays' => $holidayDates,                // ["YYYY-MM-DD", ...]
            'window_start' => $windowStart->toDateString(),
            'window_end' => $windowEnd->toDateString(),
            'transactions' => $transactions,
        ];
    }

    private function formatNullableTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('H:i');
        }

        if (is_string($value) && preg_match('/^\d{2}:\d{2}/', $value) === 1) {
            return substr($value, 0, 5);
        }

        return Carbon::parse($value)->format('H:i');
    }
}
