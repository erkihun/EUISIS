<?php

declare(strict_types=1);

namespace App\Actions\ServiceTransactions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Entitlements\RecalculateEntitlementsAction;
use App\Backbone\Contracts\ServiceEligibility;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\Employee;
use App\Models\Entitlement;
use App\Models\IdCard;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Providers\Domain\Models\ServiceProvider;

readonly class RecordServiceTransactionAction
{
    public function __construct(
        private RecalculateEntitlementsAction $recalculateEntitlementsAction,
        private WriteAuditLogAction $writeAuditLogAction,
        private ServiceEligibility $serviceEligibility,
    ) {}

    public function execute(
        Employee $employee,
        IdCard $card,
        ServiceType $serviceType,
        ServiceProvider $provider,
        ?Entitlement $entitlement,
        string $status,
        ?User $actor,
        array $metadata = [],
    ): ServiceTransaction {
        // The presented card must belong to this employee; the backbone gate refuses it otherwise.
        $eligibility = $this->serviceEligibility->checkCard(
            (string) $card->getKey(),
            $serviceType->code,
            (string) $employee->getKey(),
            $actor,
            (string) $provider->id,
        );

        if (! $eligibility->eligible) {
            throw new DomainException((string) $eligibility->reasonCode);
        }

        $transaction = DB::transaction(function () use ($employee, $card, $serviceType, $provider, $entitlement, $status, $metadata): ServiceTransaction {
            // The eligibility check above ran without a lock, so a revocation
            // committed since then would otherwise still be accepted. Locking
            // the card row (RevokeCardAction takes the same lock) and
            // re-reading it makes the two strictly ordered: a card revoked
            // first is refused here; a revoke arriving now waits for us.
            // Lock order is card, then entitlement, everywhere.
            if ($status === 'authorized') {
                $lockedCard = IdCard::query()->whereKey($card->getKey())->lockForUpdate()->first();
                $reason = $lockedCard === null ? 'no_active_id_card' : self::unusableCardReason($lockedCard);
                if ($reason !== null) {
                    throw new DomainException($reason);
                }
            }

            // The entitlement row lock serializes concurrent authorizations for
            // the same quota, so the limit check below cannot be raced.
            if ($entitlement !== null) {
                $entitlement = Entitlement::query()->whereKey($entitlement->getKey())->lockForUpdate()->firstOrFail();

                if ($status === 'authorized'
                    && $entitlement->quota_limit !== null
                    && $entitlement->quota_used >= $entitlement->quota_limit) {
                    throw new DomainException('quota_exhausted');
                }
            }

            if (($metadata['reference'] ?? null) !== null
                && ServiceTransaction::query()
                    ->where('service_provider_id', $provider->id)
                    ->where('reference', $metadata['reference'])
                    ->exists()) {
                throw new DomainException('Duplicate provider transaction reference.');
            }

            try {
                // A savepoint, so a unique-key race on the provider reference
                // (the DB backstop for the check above) leaves the outer
                // transaction usable on PostgreSQL.
                $transaction = DB::transaction(fn (): ServiceTransaction => ServiceTransaction::query()->create([
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
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('Duplicate provider transaction reference.');
            }

            if ($entitlement !== null && $status === 'authorized') {
                $entitlement->increment('quota_used');
                $this->recalculateEntitlementsAction->execute($entitlement->fresh());
            }

            return $transaction;
        });

        $this->writeAuditLogAction->execute(
            AuditEventType::ServiceTransactionRecorded,
            $actor,
            $transaction,
            $employee->currentAssignment?->organization_id,
            newValues: $transaction->toArray(),
        );

        return $transaction;
    }

    /**
     * The re-check under the card lock, with the reason codes the service gate
     * uses: a QR revoked or a card replaced since the unlocked check is refused
     * here too.
     */
    private static function unusableCardReason(IdCard $card): ?string
    {
        if ($card->revoked_at !== null || $card->status === CardStatus::Revoked || $card->qr_status === 'revoked') {
            return 'id_card_revoked';
        }
        if ($card->status !== CardStatus::Active) {
            return match ($card->status) {
                CardStatus::Lost => 'id_card_lost',
                CardStatus::Replaced => 'id_card_replaced',
                CardStatus::Expired => 'id_card_expired',
                CardStatus::Suspended => 'id_card_suspended',
                CardStatus::PendingPrint, CardStatus::Printed, CardStatus::Issued => 'id_card_pending',
                default => 'id_card_not_active',
            };
        }
        if ($card->expires_at !== null && $card->expires_at->isPast()) {
            return 'id_card_expired';
        }
        if (! $card->is_current) {
            return 'id_card_replaced';
        }

        return null;
    }
}
