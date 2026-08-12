<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaTransactionStatus;
use App\Enums\CafeteriaUsageMode;
use App\Enums\TransactionStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\IdCard;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\EmployeeServiceEligibilityService;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CafeteriaQrScanService
{
    public function __construct(
        private readonly CafeteriaEligibilityService $eligibility,
        private readonly WorkingDayCalendarService $calendar,
        private readonly CafeteriaSubsidyRuleResolver $ruleResolver,
        private readonly CafeteriaLedgerService $ledger,
        private readonly CafeteriaAvailableSubsidyService $availabilityService,
        private readonly CardQrPayloadService $qrPayloadService,
        private readonly CafeteriaSettingsService $settings,
        private readonly CafeteriaInstitutionAccessService $institutionAccess,
        private readonly WriteAuditLogAction $auditLog,
        private readonly EmployeeServiceEligibilityService $serviceEligibility,
    ) {}

    /**
     * Process a QR scan from a cafeteria terminal using the weekly window model.
     *
     * Business rules:
     *  - Cafeteria is open Mon–Fri only.  Weekend scans are rejected by default.
     *  - Public holidays are excluded from available subsidy days.
     *  - Employees may consume subsidy for any remaining working days from
     *    today through Friday of the current week.
     *  - Past days (before today) cannot be claimed retroactively.
     *  - Next week's subsidy cannot be borrowed.
     *
     * @param  array{usage_mode?: string, meal_amount?: float|null, scan_nonce?: string|null}  $options
     * @return array{
     *   allowed: bool,
     *   result_code: string,
     *   transaction: CafeteriaTransaction|null,
     *   denial_reason: string|null,
     *   is_extra_scan: bool,
     *   usage_mode: string,
     *   available_amount_before: float,
     *   subsidy_applied: float,
     *   employee_payable: float,
     *   available_days_count: int,
     *   consumed_days_count: int,
     *   week_start: string|null,
     *   week_end: string|null,
     * }
     */
    public function process(
        string $qrToken,
        CafeteriaProvider $provider,
        Carbon $scannedAt,
        ?User $actor = null,
        array $options = [],
        ?Request $request = null,
    ): array {
        $scanNonce = (string) ($options['scan_nonce'] ?? '');

        if ($scanNonce !== '') {
            $existing = CafeteriaTransaction::query()
                ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'idCard', 'consumedDays'])
                ->where('scan_nonce', $scanNonce)
                ->first();

            if ($existing !== null) {
                return $this->existingScanResult($existing);
            }
        }

        // ── Step 1: Resolve card ─────────────────────────────────────────────

        $card = $this->resolveCard($qrToken);
        if ($card === null) {
            return $this->deny('invalid_token_format');
        }

        // ── Step 2: Card validity ────────────────────────────────────────────

        // ── Step 3: Employee eligibility ─────────────────────────────────────

        $employee = $card->employee;
        $serviceEligibility = $this->serviceEligibility->check(
            $employee,
            $card,
            'cafeteria',
            $actor,
            $provider->id,
            $request,
        );

        if (! $serviceEligibility['eligible']) {
            return $this->deny(
                $serviceEligibility['reason_code'],
                $employee,
                $card,
                $serviceEligibility['message'],
            );
        }
        $eligibilityCheck = $this->eligibility->check($employee, $card);

        if (! $eligibilityCheck['eligible']) {
            return $this->deny($eligibilityCheck['reason'] ?? 'not_eligible');
        }

        // ── Step 3b: Institution access check ────────────────────────────────
        // Enforce that the employee belongs to the cafeteria provider's assigned institution.

        if (! $this->institutionAccess->canEmployeeUseProvider($employee, $provider)) {
            $this->auditLog->execute(
                AuditEventType::CafeteriaScanRejectedWrongInstitution,
                $actor,
                $provider,
                $provider->organization_id,
                newValues: [
                    'denial_reason' => 'wrong_institution',
                    'provider_id' => $provider->id,
                    'provider_organization_id' => $provider->organization_id,
                    'employee_id' => $employee->id,
                    'employee_organization_id' => $this->institutionAccess->employeeOrganizationId($employee),
                    'assigned_scope_type' => $provider->assigned_scope_type ?? 'self',
                ],
                request: $request,
            );

            return $this->deny('wrong_institution');
        }

        // ── Step 4: Transaction date in app timezone ─────────────────────────

        $transactionDate = $scannedAt->copy()->setTimezone(config('app.timezone'));

        // ── Step 5: Weekend gate ─────────────────────────────────────────────

        if (! $this->calendar->isCafeteriaOpen($transactionDate)) {
            return $this->deny($transactionDate->isWeekend() ? 'cafeteria_closed_weekend' : 'cafeteria_closed');
        }

        // ── Step 6: Public holiday gate ──────────────────────────────────────

        if (! $this->calendar->isSubsidyDay($transactionDate)) {
            return $this->deny('cafeteria_closed_holiday');
        }

        // ── Step 7: Subsidy rule ─────────────────────────────────────────────

        $rule = $this->ruleResolver->resolve($employee, $transactionDate);

        if ($rule === null) {
            return $this->deny('no_subsidy_rule');
        }

        // ── Step 8: Availability calculation ────────────────────────────────

        $availability = $this->availabilityService->calculate($employee, $transactionDate, $rule);
        $dailyAmount = $availability['daily_amount'];
        $availableDays = $this->withoutEmployeeExcludedDays($employee, $availability['available_days']);
        $availableDayCount = count($availableDays);
        $remainingSubsidy = min((float) $availability['remaining'], round($dailyAmount * $availableDayCount, 2));
        $weekStart = $availability['week_start'];
        $weekEnd = $availability['week_end'];

        // ── Step 8b: Employee leave / exclusion gate ─────────────────────────

        $leaveEmployeePayable = false;

        if ($this->settings->getBool('block_cafeteria_during_employee_leave')) {
            if ($this->isEmployeeOnLeave($employee, $transactionDate)) {
                $leaveScanMode = (string) $this->settings->get('leave_scan_mode', 'reject');

                if ($leaveScanMode === 'employee_payable') {
                    $leaveEmployeePayable = true;
                } else {
                    return $this->deny('employee_on_leave');
                }
            }
        }

        // ── Step 9: Determine requested subsidy ──────────────────────────────

        $usageModeRaw = $options['usage_mode'] ?? CafeteriaUsageMode::SingleDay->value;
        $usageMode = CafeteriaUsageMode::tryFrom($usageModeRaw) ?? CafeteriaUsageMode::SingleDay;
        $scanRequestHash = $this->scanRequestHash($qrToken, $provider, $scannedAt, $usageMode->value);

        $existingByRequestHash = CafeteriaTransaction::query()
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'idCard', 'consumedDays'])
            ->where('scan_request_hash', $scanRequestHash)
            ->first();

        if ($existingByRequestHash !== null) {
            return $this->existingScanResult($existingByRequestHash);
        }

        $requestedSubsidy = match ($usageMode) {
            CafeteriaUsageMode::SingleDay => $dailyAmount,
            CafeteriaUsageMode::UseRemainingWeek => $remainingSubsidy,
        };

        // ── Step 10: Apply subsidy up to remaining balance ───────────────────

        $mealAmount = isset($options['meal_amount']) ? (float) $options['meal_amount'] : $requestedSubsidy;

        if ($leaveEmployeePayable) {
            // Employee is on leave and leave_scan_mode = employee_payable:
            // scan is permitted but no subsidy is applied.
            $subsidyApplied = 0.0;
            $employeePayable = $mealAmount > 0 ? $mealAmount : $dailyAmount;
        } else {
            $subsidyApplied = min($requestedSubsidy, $remainingSubsidy);
            $employeePayable = max(0.0, $requestedSubsidy - $subsidyApplied);
        }

        // ── Step 11: Determine consumed dates ────────────────────────────────

        $consumedDates = $leaveEmployeePayable
            ? []
            : $this->resolveConsumedDates($usageMode, $availableDays, $dailyAmount, $subsidyApplied);
        $consumedDayCount = count($consumedDates);

        // ── Step 12: Zero-subsidy check ──────────────────────────────────────
        // If nothing was applied AND no employee payable either, reject.

        if ($subsidyApplied <= 0 && $employeePayable <= 0) {
            return $this->deny('no_available_subsidy');
        }

        // ── Step 13: Scan sequence for the day ───────────────────────────────

        $dateStr = $transactionDate->toDateString();
        $scanSequence = CafeteriaTransaction::query()
            ->where('employee_id', $employee->id)
            ->where('transaction_date', $dateStr)
            ->where('status', CafeteriaTransactionStatus::Accepted)
            ->count() + 1;

        $isExtraScan = $scanSequence > 1;

        // SingleDay mode: one scan per employee per day — reject duplicates.
        if ($isExtraScan && $usageMode === CafeteriaUsageMode::SingleDay) {
            return $this->deny('already_scanned_today');
        }

        // ── Step 14: Persist transaction + ledger ────────────────────────────

        $transaction = DB::transaction(function () use (
            $employee, $card, $provider,
            $transactionDate, $scannedAt, $dateStr,
            $mealAmount, $subsidyApplied, $employeePayable,
            $usageMode, $weekStart, $weekEnd,
            $availableDayCount, $consumedDayCount, $consumedDates, $dailyAmount,
            $isExtraScan, $scanSequence, $actor,
            $availability, $scanNonce, $scanRequestHash,
        ): CafeteriaTransaction {
            $transactionNumber = $this->generateTransactionNumber();
            $serviceType = ServiceType::query()
                ->where('code', 'cafeteria')
                ->firstOrFail();

            $serviceTransaction = ServiceTransaction::query()->create([
                'employee_id' => $employee->id,
                'id_card_id' => $card->id,
                'service_type_id' => $serviceType->id,
                'service_provider_id' => $provider->service_provider_id,
                'status' => TransactionStatus::Authorized,
                'occurred_at' => $scannedAt,
                'reference' => $transactionNumber,
                'amount' => $mealAmount,
                'metadata' => [
                    'source' => 'cafeteria_scan',
                    'usage_mode' => $usageMode->value,
                ],
            ]);

            $txn = CafeteriaTransaction::query()->create([
                'service_transaction_id' => $serviceTransaction->id,
                'transaction_number' => $transactionNumber,
                'employee_id' => $employee->id,
                'id_card_id' => $card->id,
                'cafeteria_provider_id' => $provider->id,
                'transaction_date' => $dateStr,
                'transaction_time' => $transactionDate->toTimeString(),
                'scanned_at' => $scannedAt,
                'meal_amount' => $mealAmount,
                'subsidy_amount_applied' => $subsidyApplied,
                'employee_payable_amount' => $employeePayable,
                'deduction_amount' => 0.0,
                'transaction_type' => 'scan',
                'status' => CafeteriaTransactionStatus::Accepted,
                'scan_sequence_for_day' => $scanSequence,
                'is_extra_scan' => $isExtraScan,
                'is_holiday' => false,
                'is_working_day' => true,
                'usage_mode' => $usageMode->value,
                'available_amount_before' => $availability['remaining'],
                'week_start_date' => $weekStart->toDateString(),
                'week_end_date' => $weekEnd->toDateString(),
                'available_days_count' => $availableDayCount,
                'consumed_days_count' => $consumedDayCount,
                'qr_reference' => Str::uuid()->toString(),
                'scan_nonce' => $scanNonce !== '' ? $scanNonce : null,
                'scan_request_hash' => $scanRequestHash,
                'fulfilled_at' => now(),
                'created_by' => $actor?->id,
            ]);

            foreach ($consumedDates as $consumedDate) {
                CafeteriaTransactionConsumedDay::query()->create([
                    'cafeteria_transaction_id' => $txn->id,
                    'employee_id' => $employee->id,
                    'consumed_date' => $consumedDate,
                    'subsidy_amount' => $dailyAmount,
                    'is_working_day' => true,
                    'source' => 'scan',
                ]);
            }

            // Create one ledger usage entry per consumed working day
            if (count($consumedDates) > 0) {
                $this->ledger->recordWeeklyUsage(
                    $employee,
                    $dailyAmount,
                    $consumedDates,
                    $transactionDate,
                    $txn,
                    $weekStart,
                    $weekEnd,
                    $usageMode,
                    $actor,
                );
            }

            return $txn;
        });

        return [
            'allowed' => true,
            'result_code' => $isExtraScan ? 'extra_scan_accepted' : 'scan_accepted',
            'transaction' => $transaction,
            'is_extra_scan' => $isExtraScan,
            'denial_reason' => null,
            'usage_mode' => $usageMode->value,
            'available_amount_before' => $availability['remaining'],
            'subsidy_applied' => $subsidyApplied,
            'employee_payable' => $employeePayable,
            'available_days_count' => $availableDayCount,
            'consumed_days_count' => $consumedDayCount,
            'remaining_after' => max(0.0, $availability['remaining'] - $subsidyApplied),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'consumed_dates' => $consumedDates,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveCard(string $qrToken): ?IdCard
    {
        // (a/b) Stable public_card_uuid
        $publicUuid = $this->qrPayloadService->resolvePublicUuidFromScanValue($qrToken);
        if ($publicUuid !== null) {
            $card = IdCard::query()
                ->with('employee.currentAssignment')
                ->where('public_card_uuid', $publicUuid)
                ->first();

            if ($card === null) {
                return null;
            }

            return $card;
        }

        // (c) Token format: "<card_primary_id>|<raw_token>"
        if (str_contains($qrToken, '|')) {
            [$cardId, $rawToken] = array_pad(explode('|', $qrToken, 2), 2, null);
            if ($cardId !== null && $rawToken !== null) {
                return IdCard::query()
                    ->with('employee.currentAssignment')
                    ->where('id', $cardId)
                    ->where('token_hash', hash('sha256', $rawToken))
                    ->first();
            }
        }

        // (d) Legacy URL: trailing card UUID
        if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $qrToken, $m)) {
            return IdCard::query()
                ->with('employee.currentAssignment')
                ->where('id', $m[1])
                ->first();
        }

        return null;
    }

    /**
     * Given the usage mode and available days, return which specific date
     * strings should have ledger entries created.
     *
     * @param  list<string>  $availableDays
     * @return list<string>
     */
    private function resolveConsumedDates(
        CafeteriaUsageMode $mode,
        array $availableDays,
        float $dailyAmount,
        float $subsidyApplied,
    ): array {
        if ($subsidyApplied <= 0 || empty($availableDays)) {
            return [];
        }

        return match ($mode) {
            CafeteriaUsageMode::SingleDay => array_slice($availableDays, 0, 1),
            CafeteriaUsageMode::UseRemainingWeek => $availableDays,
        };
    }

    /** @param list<string> $availableDays @return list<string> */
    private function withoutEmployeeExcludedDays(Employee $employee, array $availableDays): array
    {
        if ($availableDays === []) {
            return [];
        }

        $excludedDates = EmployeeCafeteriaExclusion::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->where('starts_on', '<=', max($availableDays))
            ->where(function ($query) use ($availableDays): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', min($availableDays));
            })
            ->get()
            ->flatMap(function (EmployeeCafeteriaExclusion $exclusion) use ($availableDays): array {
                return array_values(array_filter(
                    $availableDays,
                    fn (string $date): bool => $exclusion->isActiveOn(Carbon::parse($date)),
                ));
            })
            ->unique()
            ->all();

        return array_values(array_diff($availableDays, $excludedDates));
    }

    private function scanRequestHash(string $qrToken, CafeteriaProvider $provider, Carbon $scannedAt, string $usageMode): string
    {
        return hash('sha256', implode('|', [
            hash('sha256', $qrToken),
            $provider->id,
            $usageMode,
            $scannedAt->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ]));
    }

    /** @return array<string, mixed> */
    private function existingScanResult(CafeteriaTransaction $transaction): array
    {
        $consumedDates = $transaction->consumedDays
            ->whereNull('reversed_at')
            ->pluck('consumed_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->values()
            ->all();

        return [
            'allowed' => true,
            'result_code' => 'scan_request_already_processed',
            'transaction' => $transaction,
            'is_extra_scan' => (bool) $transaction->is_extra_scan,
            'denial_reason' => null,
            'usage_mode' => $transaction->usage_mode?->value ?? CafeteriaUsageMode::SingleDay->value,
            'available_amount_before' => (float) $transaction->available_amount_before,
            'subsidy_applied' => (float) $transaction->subsidy_amount_applied,
            'employee_payable' => (float) $transaction->employee_payable_amount,
            'available_days_count' => (int) $transaction->available_days_count,
            'consumed_days_count' => (int) $transaction->consumed_days_count,
            'remaining_after' => max(0.0, (float) $transaction->available_amount_before - (float) $transaction->subsidy_amount_applied),
            'week_start' => $transaction->week_start_date?->toDateString(),
            'week_end' => $transaction->week_end_date?->toDateString(),
            'consumed_dates' => $consumedDates,
            'duplicate' => true,
        ];
    }

    private function isEmployeeOnLeave(Employee $employee, Carbon $date): bool
    {
        return EmployeeCafeteriaExclusion::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->where('starts_on', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', $date->toDateString());
            })
            ->exists();
    }

    /** @return array{allowed: false, result_code: string, transaction: null, denial_reason: string, is_extra_scan: false, usage_mode: string, available_amount_before: float, subsidy_applied: float, employee_payable: float, available_days_count: int, consumed_days_count: int, remaining_after: float, week_start: null, week_end: null} */
    private function deny(
        string $reason,
        ?Employee $employee = null,
        ?IdCard $card = null,
        ?string $message = null,
    ): array {
        return [
            'allowed' => false,
            'result_code' => 'rejected',
            'transaction' => null,
            'is_extra_scan' => false,
            'denial_reason' => $reason,
            'denial_message' => $message ?? $reason,
            'employee' => $employee,
            'id_card' => $card,
            'card_status' => $card?->status?->value,
            'usage_mode' => CafeteriaUsageMode::SingleDay->value,
            'available_amount_before' => 0.0,
            'subsidy_applied' => 0.0,
            'employee_payable' => 0.0,
            'available_days_count' => 0,
            'consumed_days_count' => 0,
            'remaining_after' => 0.0,
            'week_start' => null,
            'week_end' => null,
        ];
    }

    private function generateTransactionNumber(): string
    {
        $prefix = 'CAF';
        $date = now()->format('Ymd');
        $seq = str_pad(
            (string) (CafeteriaTransaction::query()->whereDate('created_at', today())->count() + 1),
            5, '0', STR_PAD_LEFT,
        );

        return "{$prefix}-{$date}-{$seq}";
    }
}
