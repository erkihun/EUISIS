<?php

declare(strict_types=1);

namespace Modules\Entitlements\Application\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\Entitlements\Application\Actions\Entitlements\GrantEntitlementAction;
use Modules\Entitlements\Application\Actions\Entitlements\RecalculateEntitlementsAction;
use Modules\Entitlements\Contracts\Data\NewServiceProvider;
use Modules\Entitlements\Contracts\Data\NewServiceTransaction;
use Modules\Entitlements\Contracts\ServiceDeliveryRegistry;
use Modules\Entitlements\Domain\Models\Entitlement;
use Modules\Entitlements\Domain\Models\ServiceProvider;
use Modules\Entitlements\Domain\Models\ServiceTransaction;
use Modules\Entitlements\Domain\Models\ServiceType;

final class ServiceDeliveryRegistryService implements ServiceDeliveryRegistry
{
    public function serviceTypeId(string $code): ?string
    {
        $id = ServiceType::query()->where('code', $code)->value('id');

        return $id === null ? null : (string) $id;
    }

    public function ensureServiceType(string $code, string $nameEn, bool $active = true): string
    {
        return (string) ServiceType::query()->firstOrCreate(
            ['code' => $code],
            ['name_en' => $nameEn, 'is_active' => $active],
        )->getKey();
    }

    public function createProvider(NewServiceProvider $provider): string
    {
        $serviceTypeId = ServiceType::query()->where('code', $provider->serviceTypeCode)->value('id');
        if ($serviceTypeId === null) {
            throw new \DomainException("Unknown service type: {$provider->serviceTypeCode}");
        }

        $code = $provider->code;
        if (ServiceProvider::query()->where('code', $code)->exists()) {
            $code = $provider->collisionPrefix.'-'.$code.'-'.Str::upper(Str::random(4));
        }

        return (string) ServiceProvider::query()->create([
            'service_type_id' => $serviceTypeId,
            'organization_id' => $provider->organizationId,
            'name' => $provider->name,
            'code' => $code,
            'status' => $provider->status,
            'is_demo' => $provider->demo,
        ])->getKey();
    }

    public function recordTransaction(NewServiceTransaction $transaction): string
    {
        $serviceTypeId = ServiceType::query()->where('code', $transaction->serviceTypeCode)->value('id');
        if ($serviceTypeId === null) {
            throw new \DomainException("Unknown service type: {$transaction->serviceTypeCode}");
        }

        return (string) ServiceTransaction::query()->create([
            'employee_id' => $transaction->employeeId,
            'id_card_id' => $transaction->cardId,
            'service_type_id' => $serviceTypeId,
            'service_provider_id' => $transaction->serviceProviderId,
            'entitlement_id' => $transaction->entitlementId,
            'status' => $transaction->status,
            'occurred_at' => $transaction->occurredAt,
            'reference' => $transaction->reference,
            'amount' => $transaction->amount,
            'metadata' => $transaction->metadata,
        ])->getKey();
    }

    public function grantEntitlement(string $employeeId, string $serviceTypeId, ?string $servicePointId, int $actorId, ?int $quotaLimit = null): string
    {
        return (string) app(GrantEntitlementAction::class)->execute(
            Employee::query()->findOrFail($employeeId),
            ServiceType::query()->findOrFail($serviceTypeId),
            $servicePointId === null ? null : ServiceProvider::query()->findOrFail($servicePointId),
            User::query()->findOrFail($actorId),
            $quotaLimit,
        )->getKey();
    }

    public function recalculateEntitlementsFor(string $employeeId): void
    {
        Entitlement::query()->where('employee_id', $employeeId)->get()
            ->each(fn (Entitlement $entitlement) => app(RecalculateEntitlementsAction::class)->execute($entitlement));
    }
}
