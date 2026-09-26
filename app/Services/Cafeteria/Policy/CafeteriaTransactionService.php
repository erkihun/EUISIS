<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use App\Enums\CafeteriaTransactionStatus;
use App\Enums\CafeteriaUsageMode;
use App\Enums\TransactionStatus;
use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaLedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists an approved scan: the transaction with its historical snapshot,
 * the entitlement claims, and the employee subsidy ledger — atomically.
 *
 * The claims are inserted with their UNIQUE active_key inside a savepoint: if
 * any one of them is already taken (a concurrent scan elsewhere won), nothing
 * of this transaction is kept and EntitlementAlreadyConsumed is raised. No
 * partial consumption, on any database engine.
 */
class CafeteriaTransactionService
{
    public function __construct(private readonly CafeteriaLedgerService $ledger) {}

    /**
     * @param  array{scan_nonce?: string|null, scan_request_hash?: string|null, service_terminal_id?: string|null}  $references
     */
    public function record(
        Employee $employee,
        IdCard $card,
        Carbon $scannedAt,
        Carbon $serviceDate,
        CafeteriaPolicyResolution $resolution,
        CafeteriaEntitlementDecision $decision,
        CafeteriaPricing $pricing,
        CafeteriaUsageMode $usageMode,
        ?User $actor = null,
        array $references = [],
    ): CafeteriaTransaction {
        $policy = $resolution->policy;
        $cafeteria = $resolution->cafeteria;

        try {
            return DB::transaction(function () use ($employee, $card, $scannedAt, $serviceDate, $resolution, $decision, $pricing, $usageMode, $actor, $references, $policy, $cafeteria): CafeteriaTransaction {
                $number = 'CAF-'.$serviceDate->format('Ymd').'-'.Str::upper(Str::random(6));
                $dailySubsidy = $pricing->employeePaid ? 0 : CafeteriaPricing::toCents((string) $policy->daily_subsidy_amount);
                $availableBefore = CafeteriaPricing::format($dailySubsidy * count($decision->availableDates));

                $serviceTransaction = ServiceTransaction::query()->create([
                    'employee_id' => $employee->id,
                    'id_card_id' => $card->id,
                    'service_type_id' => ServiceType::query()->where('code', 'cafeteria')->value('id'),
                    'service_provider_id' => $cafeteria->service_provider_id,
                    'status' => TransactionStatus::Authorized,
                    'occurred_at' => $scannedAt,
                    'reference' => $number,
                    'amount' => $pricing->total(),
                    'metadata' => ['source' => 'cafeteria_scan', 'usage_mode' => $usageMode->value],
                ]);

                $transaction = CafeteriaTransaction::query()->create([
                    'service_transaction_id' => $serviceTransaction->id,
                    'transaction_number' => $number,
                    'employee_id' => $employee->id,
                    'id_card_id' => $card->id,
                    'cafeteria_provider_id' => $cafeteria->id,
                    'transaction_date' => $serviceDate->toDateString(),
                    'transaction_time' => $serviceDate->toTimeString(),
                    'scanned_at' => $scannedAt,
                    // Ownership: employee organization pays, cafeteria served, provider is paid.
                    'employee_assignment_id' => $resolution->employeeAssignment?->id,
                    'employee_organization_id' => $resolution->employeeOrganizationId(),
                    'cafeteria_service_network_id' => $resolution->network?->id,
                    'provider_id' => $resolution->provider?->id,
                    'cafeteria_service_assignment_id' => $resolution->serviceAssignment?->id,
                    'cafeteria_service_policy_id' => $policy->id,
                    'cafeteria_policy_version' => $policy->version_no,
                    'policy_snapshot' => [...$policy->snapshot(), 'entitlement_dates' => $decision->consumedDates()],
                    'pricing_source' => 'policy',
                    // Applied amounts are final: reports and settlements read these, never the policy.
                    'meal_amount' => $pricing->total(),
                    'subsidy_amount_applied' => $pricing->subsidy(),
                    'employee_payable_amount' => $pricing->employeeAmount(),
                    'employee_contribution_applied' => $pricing->employeeAmount(),
                    'provider_price_applied' => $pricing->unitPrice(),
                    'total_amount_applied' => $pricing->total(),
                    'currency_code' => $pricing->currency,
                    'deduction_amount' => 0,
                    'transaction_type' => $pricing->employeePaid ? 'employee_paid' : 'scan',
                    'status' => CafeteriaTransactionStatus::Accepted,
                    'scan_sequence_for_day' => $decision->scanSequence,
                    'is_extra_scan' => $decision->isExtraScan,
                    'is_holiday' => $decision->isHoliday,
                    'is_working_day' => $decision->isEntitlementDay,
                    'usage_mode' => $usageMode->value,
                    'available_amount_before' => $availableBefore,
                    'week_start_date' => $decision->weekStart?->toDateString(),
                    'week_end_date' => $decision->weekEnd?->toDateString(),
                    'available_days_count' => count($decision->availableDates),
                    'consumed_days_count' => count($decision->entitlements),
                    'qr_reference' => (string) Str::uuid(),
                    'scan_nonce' => ($references['scan_nonce'] ?? '') !== '' ? $references['scan_nonce'] : null,
                    'scan_request_hash' => $references['scan_request_hash'] ?? null,
                    'service_terminal_id' => $references['service_terminal_id'] ?? null,
                    'fulfilled_at' => now(),
                    'created_by' => $actor?->id,
                ]);

                foreach ($decision->entitlements as $claim) {
                    CafeteriaTransactionConsumedDay::query()->create([
                        'cafeteria_transaction_id' => $transaction->id,
                        'employee_id' => $employee->id,
                        'consumed_date' => $claim['date'],
                        'subsidy_amount' => CafeteriaPricing::format($dailySubsidy),
                        'is_working_day' => true,
                        'source' => 'scan',
                        'organization_id' => $resolution->employeeOrganizationId(),
                        'entitlement_type' => CafeteriaTransactionConsumedDay::ENTITLEMENT_MEAL,
                        'slot_no' => $claim['slot'],
                        'cafeteria_service_network_id' => $resolution->network?->id,
                        'consumed_at_cafeteria_id' => $cafeteria->id,
                        'provider_id' => $resolution->provider?->id,
                        'usage_type' => $claim['usage_type'],
                        'status' => 'consumed',
                        'active_key' => CafeteriaTransactionConsumedDay::activeKey($employee->id, $claim['date'], CafeteriaTransactionConsumedDay::ENTITLEMENT_MEAL, $claim['slot']),
                    ]);
                }

                if ($decision->entitlements !== []) {
                    $this->ledger->recordWeeklyUsage(
                        $employee,
                        (float) CafeteriaPricing::format($dailySubsidy),
                        $decision->consumedDates(),
                        $serviceDate,
                        $transaction,
                        $decision->weekStart ?? $serviceDate,
                        $decision->weekEnd ?? $serviceDate,
                        $usageMode,
                        $actor,
                    );
                }

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The savepoint above already undid every row of this attempt. A
            // clash on the scan nonce/hash is a retry, left for the caller to
            // answer idempotently; only the entitlement key means "consumed".
            if (str_contains($exception->getMessage(), 'ctcd_active_entitlement_unique') || str_contains($exception->getMessage(), 'active_key')) {
                throw new EntitlementAlreadyConsumed('An entitlement in this claim was consumed by another scan.', 0, $exception);
            }

            throw $exception;
        }
    }
}
