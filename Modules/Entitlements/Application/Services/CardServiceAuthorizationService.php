<?php

declare(strict_types=1);

namespace Modules\Entitlements\Application\Services;

use App\Models\IdCard;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Modules\Entitlements\Application\Actions\ServiceTransactions\RecordServiceTransactionAction;
use Modules\Entitlements\Application\Actions\ServiceTransactions\VerifyCardForServiceAction;
use Modules\Entitlements\Contracts\CardServiceAuthorization;
use Modules\Entitlements\Contracts\Data\ServiceAuthorizationOutcome;
use Modules\Entitlements\Domain\Models\Entitlement;
use Modules\Entitlements\Domain\Models\ServiceProvider;
use Modules\Entitlements\Domain\Models\ServiceType;

/** Card checks for the APIs and NFC terminals (moved here from the API controllers). */
final class CardServiceAuthorizationService implements CardServiceAuthorization
{
    public function __construct(
        private readonly VerifyCardForServiceAction $verify,
        private readonly RecordServiceTransactionAction $record,
    ) {}

    public function verifyToken(string $token, string $serviceTypeId, ?string $servicePointId, ?User $actor = null, ?Request $request = null): array
    {
        $serviceType = ServiceType::query()->findOrFail($serviceTypeId);
        $provider = $servicePointId === null ? null : ServiceProvider::query()->find($servicePointId);

        return $this->verify->execute($token, $serviceType, $provider, $actor, $request);
    }

    public function authorizeCard(string $cardId, string $serviceTypeId, string $servicePointId, bool $record, ?string $reference, ?Request $request = null): ServiceAuthorizationOutcome
    {
        $card = IdCard::query()->with('employee.currentAssignment')->findOrFail($cardId);
        $serviceType = ServiceType::query()->findOrFail($serviceTypeId);
        $provider = ServiceProvider::query()->findOrFail($servicePointId);

        // Lock order is card, then entitlement, everywhere (RecordServiceTransactionAction).
        $entitlement = Entitlement::query()
            ->where('employee_id', $card->employee_id)
            ->where('service_type_id', $serviceType->id)
            ->where(fn ($q) => $q->whereNull('service_provider_id')->orWhere('service_provider_id', $provider->id))
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->lockForUpdate()
            ->first();

        $check = $this->verify->verifyResolvedCard($card, $serviceType, $provider, null, $request);
        if (! $check['allowed']) {
            return new ServiceAuthorizationOutcome(false, (string) $check['result_code'], null);
        }
        if (! $record) {
            return new ServiceAuthorizationOutcome(true, (string) $check['result_code'], null);
        }

        try {
            $transaction = $this->record->execute($card->employee, $card, $serviceType, $provider, $entitlement, 'authorized', null, ['reference' => $reference, 'source' => 'nfc']);
        } catch (DomainException) {
            return new ServiceAuthorizationOutcome(false, 'duplicate_transaction', null);
        }

        return new ServiceAuthorizationOutcome(true, (string) $check['result_code'], $transaction->reference);
    }
}
