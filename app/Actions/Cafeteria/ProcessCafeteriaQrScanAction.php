<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\IdCard;
use App\Models\ProviderUser;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaQrScanService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

readonly class ProcessCafeteriaQrScanAction
{
    public function __construct(
        private CafeteriaQrScanService $scanService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    /**
     * The actor is a staff user (back office, terminals) or a provider portal
     * operator. Only a staff user can be written to the users-keyed actor
     * columns; an operator is recorded by id in the transaction and audit.
     *
     * @param  array{usage_mode?: string|null, meal_amount?: float|null, scan_nonce?: string|null}  $options
     * @return array{allowed: bool, result_code: string, transaction: CafeteriaTransaction|null, denial_reason: string|null}
     */
    public function execute(
        string|IdCard $qrToken,
        CafeteriaProvider $provider,
        ?Carbon $scannedAt = null,
        User|ProviderUser|null $actor = null,
        ?Request $request = null,
        array $options = [],
    ): array {
        $scannedAt ??= Carbon::now();
        $operatorId = $actor instanceof ProviderUser ? $actor->getKey() : null;
        $actor = $actor instanceof User ? $actor : null;

        $result = $this->scanService->process($qrToken, $provider, $scannedAt, $actor, [...$options, 'provider_user_id' => $operatorId], $request);

        $transaction = $result['transaction'];
        // Scan events belong to the billing owner: the employee organization,
        // not the organization the cafeteria primarily serves.
        $orgId = $transaction?->employee_organization_id ?? ($result['employee_organization_id'] ?? null);

        if ($result['allowed'] && $transaction !== null) {
            $usageMode = $result['usage_mode'] ?? 'single_day';
            $eventType = match (true) {
                ($result['duplicate'] ?? false) => AuditEventType::CafeteriaScanDuplicateBlocked,
                $usageMode === 'use_remaining_week' => AuditEventType::CafeteriaTransactionWeeklyUsage,
                $result['is_extra_scan'] => AuditEventType::CafeteriaTransactionExtraScan,
                default => AuditEventType::CafeteriaScanProcessed,
            };

            $this->writeAuditLogAction->execute(
                $eventType,
                $actor,
                $transaction,
                $orgId,
                newValues: [
                    'transaction_number' => $transaction->transaction_number,
                    'employee_id' => $transaction->employee_id,
                    'provider_id' => $provider->id,
                    'usage_mode' => $usageMode,
                    'available_amount_before' => $result['available_amount_before'],
                    'subsidy_applied' => $result['subsidy_applied'],
                    'employee_payable' => $result['employee_payable'],
                    'available_days_count' => $result['available_days_count'],
                    'consumed_days_count' => $result['consumed_days_count'],
                    'week_start_date' => $result['week_start'],
                    'week_end_date' => $result['week_end'],
                    'scan_nonce' => $options['scan_nonce'] ?? null,
                    'consumed_dates' => $result['consumed_dates'] ?? [],
                    'duplicate' => $result['duplicate'] ?? false,
                    'employee_organization_id' => $transaction->employee_organization_id,
                    'payee_provider_id' => $transaction->provider_id,
                    'cafeteria_service_policy_id' => $transaction->cafeteria_service_policy_id,
                    'cafeteria_policy_version' => $transaction->cafeteria_policy_version,
                    'scanned_by_provider_user_id' => $operatorId,
                ],
                request: $request,
            );
        } elseif (! $result['allowed']) {
            $denialReason = $result['denial_reason'];
            $eventType = match ($denialReason) {
                'cafeteria_closed_weekend' => AuditEventType::CafeteriaTransactionWeekendRejected,
                'cafeteria_closed_holiday' => AuditEventType::CafeteriaTransactionHolidayRejected,
                default => AuditEventType::CafeteriaTransactionScanned,
            };

            $this->writeAuditLogAction->execute(
                $eventType,
                $actor,
                null,
                $orgId,
                newValues: [
                    'result_code' => $result['result_code'],
                    'denial_reason' => $denialReason,
                    'provider_id' => $provider->id,
                    'scanned_by_provider_user_id' => $operatorId,
                ],
                request: $request,
            );
        }

        return $result;
    }
}
