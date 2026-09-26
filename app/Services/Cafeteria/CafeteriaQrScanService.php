<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaTransactionStatus;
use App\Enums\CafeteriaUsageMode;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use App\Services\Cafeteria\Policy\CafeteriaEntitlementService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyResolver;
use App\Services\Cafeteria\Policy\CafeteriaPricing;
use App\Services\Cafeteria\Policy\CafeteriaTransactionPricingService;
use App\Services\Cafeteria\Policy\CafeteriaTransactionService;
use App\Services\Cafeteria\Policy\EntitlementAlreadyConsumed;
use App\Services\EmployeeServiceEligibilityService;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One cafeteria scan, whatever the credential: a QR token string, or an
 * IdCard a server-side NFC verifier already resolved. Only credential
 * verification differs; eligibility, policy, entitlement, pricing and
 * persistence are the same services for both (docs/cafeteria-policy-architecture.md).
 *
 *  card → employee eligibility → policy of the EMPLOYEE organization for the
 *  scanned cafeteria on the service date → cafeteria availability and
 *  employee entitlement → price from the policy → claim the entitlement
 *  across all branches → transaction with its snapshot.
 *
 * Money comes only from the resolved policy; any client amount is ignored.
 */
class CafeteriaQrScanService
{
    /** Resolution failures that mean "this organization may not eat here". */
    private const ACCESS_DENIALS = ['no_cafeteria_access', 'location_not_allowed', 'no_service_assignment', 'cafeteria_not_in_network'];

    public function __construct(
        private readonly CafeteriaEligibilityService $eligibility,
        private readonly CardQrPayloadService $qrPayloadService,
        private readonly CafeteriaSettingsService $settings,
        private readonly WriteAuditLogAction $auditLog,
        private readonly EmployeeServiceEligibilityService $serviceEligibility,
        private readonly CafeteriaPolicyResolver $policyResolver,
        private readonly CafeteriaEntitlementService $entitlements,
        private readonly CafeteriaTransactionPricingService $pricing,
        private readonly CafeteriaTransactionService $transactions,
    ) {}

    /**
     * Any amount in $options (e.g. a terminal's `meal_amount`) is ignored.
     *
     * @param  array{usage_mode?: string|null, scan_nonce?: string|null, service_terminal_id?: string|null}  $options
     * @return array<string, mixed>
     */
    public function process(
        string|IdCard $qrToken,
        CafeteriaProvider $provider,
        Carbon $scannedAt,
        ?User $actor = null,
        array $options = [],
        ?Request $request = null,
        bool $dryRun = false,
    ): array {
        return DB::transaction(function () use ($qrToken, $provider, $scannedAt, $actor, $options, $request, $dryRun): array {
            $resolved = $qrToken instanceof IdCard ? $qrToken : $this->resolveCard($qrToken);
            if ($resolved !== null) {
                // Serializes every scanner working on this employee, at any branch.
                IdCard::whereKey($resolved->id)->lockForUpdate()->first();
                Employee::whereKey($resolved->employee_id)->lockForUpdate()->first();
            }

            return $this->processResolvedInput($qrToken, $provider, $scannedAt, $actor, $options, $request, $dryRun);
        });
    }

    private function processResolvedInput(
        string|IdCard $qrToken,
        CafeteriaProvider $cafeteria,
        Carbon $scannedAt,
        ?User $actor,
        array $options,
        ?Request $request,
        bool $dryRun,
    ): array {
        $scanNonce = (string) ($options['scan_nonce'] ?? '');

        if ($scanNonce !== '' && ($existing = $this->existingTransaction('scan_nonce', $scanNonce)) !== null) {
            return $this->existingScanResult($existing);
        }

        // ── Credential → card → employee ─────────────────────────────────────
        $card = $qrToken instanceof IdCard ? $qrToken : $this->resolveCard($qrToken);
        if ($card === null) {
            return $this->deny('invalid_token_format');
        }

        $employee = $card->employee;
        $serviceEligibility = $this->serviceEligibility->check($employee, $card, 'cafeteria', $actor, $cafeteria->id, $request);
        if (! $serviceEligibility['eligible']) {
            return $this->deny($serviceEligibility['reason_code'], $employee, $card, $serviceEligibility['message']);
        }

        $eligibilityCheck = $this->eligibility->check($employee, $card);
        if (! $eligibilityCheck['eligible']) {
            return $this->deny($eligibilityCheck['reason'] ?? 'not_eligible');
        }

        $serviceAt = $scannedAt->copy()->setTimezone(config('app.timezone'));

        // ── Policy of the employee organization for this cafeteria ───────────
        $resolution = $this->policyResolver->resolve($employee, $cafeteria, $serviceAt);
        if (! $resolution->resolved()) {
            if (in_array($resolution->reason, self::ACCESS_DENIALS, true)) {
                $this->auditLog->execute(
                    AuditEventType::CafeteriaScanRejectedWrongInstitution,
                    $actor,
                    $cafeteria,
                    $resolution->employeeOrganizationId(),
                    newValues: ['denial_reason' => $resolution->reason, ...$resolution->toAuditArray()],
                    request: $request,
                );
            }

            return $this->deny($resolution->reason, $employee, $card, $this->denialMessage($resolution->reason));
        }

        // ── Cafeteria availability + employee entitlement ────────────────────
        $usageMode = CafeteriaUsageMode::tryFrom((string) ($options['usage_mode'] ?? $this->settings->defaultUsageMode()))
            ?? CafeteriaUsageMode::SingleDay;
        $decision = $this->entitlements->evaluate($employee, $cafeteria, $serviceAt, $resolution, $usageMode);
        if (! $decision->eligible) {
            return $this->deny($decision->reason, $employee, $card, $decision->message ?? $this->denialMessage($decision->reason));
        }

        $scanRequestHash = $this->scanRequestHash($qrToken instanceof IdCard ? 'nfc:'.$card->id : $qrToken, $cafeteria, $scannedAt, $usageMode->value);
        if (($existing = $this->existingTransaction('scan_request_hash', $scanRequestHash)) !== null) {
            return $this->existingScanResult($existing);
        }

        // ── Price from the policy; system-wide safety limits ─────────────────
        $pricing = $this->pricing->price($resolution->policy, count($decision->entitlements), $decision->isEmployeePaid());

        $maxTransaction = $this->settings->get('max_transaction_amount_per_scan');
        if ($maxTransaction !== null && $pricing->totalCents() > CafeteriaPricing::toCents((string) $maxTransaction)) {
            return $this->deny('transaction_limit_exceeded', $employee, $card, __('cafeteria.transactionLimitExceeded'));
        }

        $maxExtra = $this->settings->get('max_extra_amount_per_week');
        if ($decision->isExtraScan && $decision->isEmployeePaid() && $maxExtra !== null) {
            $usedExtra = CafeteriaTransaction::query()
                ->where('employee_id', $employee->id)
                ->where('status', CafeteriaTransactionStatus::Accepted)
                ->where('is_extra_scan', true)
                ->whereDate('transaction_date', '>=', $decision->weekStart->toDateString())
                ->whereDate('transaction_date', '<=', $decision->weekStart->copy()->addDays(6)->toDateString())
                ->sum('employee_payable_amount');

            if (CafeteriaPricing::toCents((string) $usedExtra) + $pricing->employeeCents > CafeteriaPricing::toCents((string) $maxExtra)) {
                return $this->deny('weekly_extra_limit_exceeded', $employee, $card, __('cafeteria.weeklyExtraLimitExceeded'));
            }
        }

        if ($dryRun) {
            return ['allowed' => true, 'result_code' => 'eligible', 'transaction' => null, 'duplicate' => false];
        }

        // ── Claim the entitlement and record the transaction ─────────────────
        try {
            $transaction = $this->transactions->record(
                $employee,
                $card,
                $scannedAt,
                $serviceAt,
                $resolution,
                $decision,
                $pricing,
                $usageMode,
                $actor,
                [
                    'scan_nonce' => $scanNonce,
                    'scan_request_hash' => $scanRequestHash,
                    'service_terminal_id' => $options['service_terminal_id'] ?? null,
                ],
            );
        } catch (EntitlementAlreadyConsumed) {
            return $this->deny('entitlement_already_consumed', $employee, $card, $this->denialMessage('entitlement_already_consumed'));
        } catch (UniqueConstraintViolationException $exception) {
            // A retry of the same scan raced this one: answer with its result.
            $existing = ($scanNonce !== '' ? $this->existingTransaction('scan_nonce', $scanNonce) : null)
                ?? $this->existingTransaction('scan_request_hash', $scanRequestHash);

            if ($existing === null) {
                throw $exception;
            }

            return $this->existingScanResult($existing);
        }

        $subsidy = (float) $pricing->subsidy();
        $availableBefore = (float) $transaction->available_amount_before;

        return [
            'allowed' => true,
            'result_code' => $decision->isExtraScan ? 'extra_scan_accepted' : 'scan_accepted',
            'transaction' => $transaction,
            'is_extra_scan' => $decision->isExtraScan,
            'denial_reason' => null,
            'usage_mode' => $usageMode->value,
            'available_amount_before' => $availableBefore,
            'subsidy_applied' => $subsidy,
            'employee_payable' => (float) $pricing->employeeAmount(),
            'total_amount' => (float) $pricing->total(),
            'available_days_count' => count($decision->availableDates),
            'consumed_days_count' => count($decision->entitlements),
            'remaining_after' => max(0.0, round($availableBefore - $subsidy, 2)),
            'week_start' => $decision->weekStart?->toDateString(),
            'week_end' => $decision->weekEnd?->toDateString(),
            'consumed_dates' => $decision->consumedDates(),
            'employee_organization_id' => $resolution->employeeOrganizationId(),
            'provider_id' => $resolution->provider?->id,
            'cafeteria_service_policy_id' => $resolution->policy->id,
            'policy_version' => $resolution->policy->version_no,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveCard(string $qrToken): ?IdCard
    {
        // (a/b) Stable public_card_uuid
        $publicUuid = $this->qrPayloadService->resolvePublicUuidFromScanValue($qrToken);
        if ($publicUuid !== null) {
            return IdCard::query()
                ->with('employee.currentAssignment')
                ->where('public_card_uuid', $publicUuid)
                ->first();
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

    private function denialMessage(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $key = 'cafeteria-policy.denial.'.$reason;
        $message = __($key);

        return $message === $key ? null : $message;
    }

    private function existingTransaction(string $column, string $value): ?CafeteriaTransaction
    {
        return CafeteriaTransaction::query()
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'idCard', 'consumedDays'])
            ->where($column, $value)
            ->first();
    }

    private function scanRequestHash(string $qrToken, CafeteriaProvider $cafeteria, Carbon $scannedAt, string $usageMode): string
    {
        return hash('sha256', implode('|', [
            hash('sha256', $qrToken),
            $cafeteria->id,
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
            'total_amount' => (float) ($transaction->total_amount_applied ?? $transaction->meal_amount),
            'available_days_count' => (int) $transaction->available_days_count,
            'consumed_days_count' => (int) $transaction->consumed_days_count,
            'remaining_after' => max(0.0, (float) $transaction->available_amount_before - (float) $transaction->subsidy_amount_applied),
            'week_start' => $transaction->week_start_date?->toDateString(),
            'week_end' => $transaction->week_end_date?->toDateString(),
            'consumed_dates' => $consumedDates,
            'duplicate' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function deny(
        ?string $reason,
        ?Employee $employee = null,
        ?IdCard $card = null,
        ?string $message = null,
    ): array {
        $reason ??= 'not_eligible';

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
}
