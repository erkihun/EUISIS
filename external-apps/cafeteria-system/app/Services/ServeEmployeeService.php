<?php

declare(strict_types=1);

namespace CafeteriaSystem\Services;

use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaOrganizationAssignment;
use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The scan-to-serve pipeline.
 *
 * Order matters and every step fails closed:
 *
 *   1. verify the card token with EUISIS      → card must resolve and be valid
 *   2. confirm the employee is active         → from the same verified response
 *   3. confirm EUISIS service eligibility     → EUISIS decides, not the cafeteria
 *   4. confirm the employee's ORGANIZATION is
 *      assigned to THIS cafeteria, effective
 *      today                                  → local cafeteria rule
 *   5. confirm the cafeteria is OPEN today    → local cafeteria rule
 *   6. reject a duplicate claim for today     → local cafeteria rule
 *   7. record the transaction locally         → minimal snapshot only
 *
 * Steps 1–3 are EUISIS's authority. Steps 4–6 are this system's: EUISIS knows
 * whether an employee may eat, but only the cafeteria system knows which
 * service point is contracted to feed them.
 */
readonly class ServeEmployeeService
{
    public function __construct(
        private EuisisApiClient $client,
        private CafeteriaCalendarService $calendar,
    ) {}

    /**
     * @return array{served: bool, result_code: string, message_key: string, transaction: CafeteriaServiceTransaction|null, snapshot: array<string, mixed>, assignment: CafeteriaOrganizationAssignment|null}
     */
    public function serve(
        string $cardToken,
        Cafeteria $cafeteria,
        ?string $servedByUserId = null,
        string $serviceType = 'meal',
        string $usageMode = 'single_day',
    ): array {
        // ── 1. Verify the scanned card ──────────────────────────────────
        $verification = $this->client->verifyCard($cardToken);

        if (! $verification['ok']) {
            return $this->deny($this->transportReason($verification['error']), 'cafeteria.verificationFailed');
        }

        $payload = $verification['data'];

        if (($payload['valid'] ?? false) !== true) {
            return $this->deny(
                'card_'.(string) ($payload['status'] ?? 'not_active'),
                'cafeteria.idCardNotActive',
                $this->snapshotFrom($payload),
            );
        }

        // ── 2. Employee must be active ──────────────────────────────────
        $employee = $payload['employee'] ?? null;

        if ($employee === null || ($employee['status'] ?? null) !== 'active') {
            return $this->deny('employee_inactive', 'cafeteria.employeeNotEligible', $this->snapshotFrom($payload));
        }

        $snapshot = $this->snapshotFrom($payload);

        // ── 3. EUISIS decides service eligibility ───────────────────────
        $employeeId = (string) ($payload['employee_id'] ?? $employee['id'] ?? '');

        if ($employeeId !== '') {
            $eligibility = $this->client->checkServiceEligibility($employeeId);

            if (! $eligibility['ok'] || ($eligibility['data']['eligible'] ?? false) !== true) {
                return $this->deny(
                    (string) ($eligibility['data']['reason_code'] ?? 'not_eligible'),
                    'cafeteria.employeeNotEligible',
                    $snapshot,
                );
            }
        }

        // ── 4. Organization must be assigned to THIS cafeteria ──────────
        $organizationCode = $snapshot['organization_code'];

        if (blank($organizationCode)) {
            return $this->deny('organization_unknown', 'cafeteria.organizationNotAssigned', $snapshot);
        }

        $assignment = CafeteriaOrganizationAssignment::query()
            ->where('cafeteria_id', $cafeteria->getKey())
            ->where('organization_code', $organizationCode)
            ->effectiveOn()
            ->first();

        if ($assignment === null) {
            return $this->deny('organization_not_assigned', 'cafeteria.organizationNotAssigned', $snapshot);
        }

        // ── 5. The cafeteria must be open today ─────────────────────────
        // The calendar already computed this for the scan terminal, but the
        // serve path never consulted it: a weekend, public holiday or
        // no-subsidy day was served anyway, contradicting the configured
        // working days and the red/amber cell the operator could see.
        $today = $this->calendar->days(
            $cafeteria->provider_id,
            $cafeteria->getKey(),
            now(),
            now(),
        )[0] ?? null;

        if ($today !== null && ! $today['is_open']) {
            return $this->deny(
                $today['reason_code'] === 'public_holiday' ? 'closed_public_holiday' : 'closed_today',
                'cafeteria.cafeteriaClosedToday',
                $snapshot,
                $assignment,
            );
        }

        if ($today !== null && ! $today['is_subsidy_day']) {
            return $this->deny('no_subsidy_today', 'cafeteria.noSubsidyToday', $snapshot, $assignment);
        }

        // ── 6 & 7. Duplicate guard + local record, atomically ───────────
        return DB::transaction(function () use ($snapshot, $cafeteria, $servedByUserId, $serviceType, $usageMode, $organizationCode, $cardToken, $assignment): array {
            $alreadyServed = CafeteriaServiceTransaction::query()
                ->where('employee_number', $snapshot['employee_number'])
                ->where('cafeteria_id', $cafeteria->getKey())
                ->where('service_type', $serviceType)
                ->whereDate('service_date', now()->toDateString())
                ->where('status', 'served')
                ->lockForUpdate()
                ->exists();

            if ($alreadyServed) {
                return $this->deny('already_served_today', 'cafeteria.duplicateService', $snapshot, $assignment);
            }

            $transaction = CafeteriaServiceTransaction::query()->create([
                'transaction_number' => 'CAF-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'provider_id' => $cafeteria->provider_id,
                'cafeteria_id' => $cafeteria->getKey(),
                'organization_code' => $organizationCode,
                'employee_number' => $snapshot['employee_number'],
                'employee_name' => $snapshot['employee_name'],
                'organization_name' => $snapshot['organization_name'],
                'card_status' => $snapshot['card_status'],
                // Hash, never the token itself.
                'card_token_hash' => hash('sha256', $cardToken),
                'eligibility_result' => 'eligible',
                'status' => 'served',
                'service_type' => $serviceType,
                // Recorded as authorised, so a later default change cannot
                // retroactively reinterpret this transaction.
                'usage_mode' => $usageMode,
                'service_date' => now()->toDateString(),
                'served_at' => now(),
                'served_by_user_id' => $servedByUserId,
            ]);

            return [
                'served' => true,
                'result_code' => 'served',
                'message_key' => 'cafeteria.serviceRecorded',
                'transaction' => $transaction,
                'snapshot' => $snapshot,
                'assignment' => $assignment,
            ];
        });
    }

    /** Map a transport failure to a specific, actionable reason code. */
    private function transportReason(?string $error): string
    {
        return match ($error) {
            'not_found' => 'card_not_found',
            'missing_api_token' => 'missing_api_token',
            'unauthorized' => 'api_token_rejected',
            'missing_scope', 'forbidden' => 'api_scope_denied',
            'rate_limited' => 'api_rate_limited',
            'connection_failed' => 'euisis_unreachable',
            default => 'verification_unavailable',
        };
    }

    /**
     * Minimal verified snapshot. Deliberately excludes national id, phone,
     * email, address, salary and documents.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function snapshotFrom(array $payload): array
    {
        return [
            'employee_number' => $payload['employee']['employee_number'] ?? null,
            'employee_name' => $payload['employee']['full_name'] ?? null,
            // Shown to the operator for identity confirmation, then discarded.
            // Deliberately NOT in the transaction's fillable list — the photo
            // is displayed, never stored in the cafeteria database.
            'photo_url' => $payload['employee']['photo_url'] ?? null,
            'organization_code' => $payload['organization']['code'] ?? null,
            'organization_name' => $payload['organization']['name_en'] ?? null,
            'position_name' => $payload['position']['title_en'] ?? null,
            'card_status' => $payload['status'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{served: false, result_code: string, message_key: string, transaction: null, snapshot: array<string, mixed>, assignment: CafeteriaOrganizationAssignment|null}
     */
    private function deny(
        string $resultCode,
        string $messageKey,
        array $snapshot = [],
        ?CafeteriaOrganizationAssignment $assignment = null,
    ): array {
        return [
            'served' => false,
            'result_code' => $resultCode,
            'message_key' => $messageKey,
            'transaction' => null,
            'snapshot' => $snapshot,
            'assignment' => $assignment,
        ];
    }
}
