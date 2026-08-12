<?php

declare(strict_types=1);

namespace App\Actions\ServiceTransactions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Entitlements\RecalculateEntitlementsAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\Entitlement;
use App\Models\IdCard;
use App\Models\ServiceProvider;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\EmployeeServiceEligibilityService;
use DomainException;

readonly class RecordServiceTransactionAction
{
    public function __construct(
        private RecalculateEntitlementsAction $recalculateEntitlementsAction,
        private WriteAuditLogAction $writeAuditLogAction,
        private EmployeeServiceEligibilityService $serviceEligibility,
    ) {}

    public function execute(
        Employee $employee,
        IdCard $card,
        ServiceType $serviceType,
        ServiceProvider $provider,
        ?Entitlement $entitlement,
        string $status,
        User $actor,
        array $metadata = [],
    ): ServiceTransaction {
        $eligibility = $this->serviceEligibility->check(
            $employee,
            $card,
            $serviceType->code,
            $actor,
            $provider->id,
        );

        if (! $eligibility['eligible']) {
            throw new DomainException($eligibility['reason_code']);
        }

        if (($metadata['reference'] ?? null) !== null) {
            $duplicate = ServiceTransaction::query()
                ->where('service_provider_id', $provider->id)
                ->where('reference', $metadata['reference'])
                ->exists();

            if ($duplicate) {
                throw new DomainException('Duplicate provider transaction reference.');
            }
        }

        $transaction = ServiceTransaction::query()->create([
            'employee_id' => $employee->id,
            'id_card_id' => $card->id,
            'service_type_id' => $serviceType->id,
            'service_provider_id' => $provider->id,
            'entitlement_id' => $entitlement?->id,
            'status' => $status,
            'occurred_at' => now(),
            'reference' => $metadata['reference'] ?? null,
            'amount' => $metadata['amount'] ?? null,
            'metadata' => $metadata,
        ]);

        if ($entitlement !== null && $status === 'authorized') {
            $entitlement->increment('quota_used');
            $this->recalculateEntitlementsAction->execute($entitlement->fresh());
        }

        $this->writeAuditLogAction->execute(
            AuditEventType::ServiceTransactionRecorded,
            $actor,
            $transaction,
            $employee->currentAssignment?->organization_id,
            newValues: $transaction->toArray(),
        );

        return $transaction;
    }
}
