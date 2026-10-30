<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Services\Network;

use App\Models\User;
use Modules\Providers\Contracts\Data\NewProvider;
use Modules\Providers\Contracts\Data\ProviderDetails;
use Modules\Providers\Contracts\ProviderRegistry;
use Modules\Providers\Domain\Models\Provider;

/**
 * Providers as payees: the organization that operates cafeterias and is paid
 * for their service. A cafeteria provider always carries the `cafeteria`
 * service, which the provider portal requires to open its cafeteria pages.
 * The provider records themselves are written by the Providers module.
 */
class CafeteriaProviderRegistry
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Provider
    {
        $providerId = $this->providers->register(new NewProvider(
            code: strtoupper(trim((string) $data['provider_code'])),
            nameEn: trim((string) $data['name_en']),
            nameAm: filled($data['name_am'] ?? null) ? trim((string) $data['name_am']) : null,
            typeCode: 'CAFETERIA',
            typeNameIfMissing: 'Cafeteria',
            serviceCode: 'cafeteria',
            serviceTypeNameIfMissing: 'Cafeteria Service',
            contactPerson: $data['contact_person'] ?? null,
            phoneNumber: $data['phone_number'] ?? null,
            email: $data['email'] ?? null,
            address: $data['address'] ?? null,
            status: $data['status'] ?? 'active',
            metadata: array_filter(['settlement_details' => $data['settlement_details'] ?? null]),
            actorId: $actor->id,
            stampUpdatedBy: true,
        ));

        return Provider::query()->findOrFail($providerId);
    }

    /** @param array<string, mixed> $data */
    public function update(Provider $provider, array $data, User $actor): Provider
    {
        $this->providers->updateDetails((string) $provider->id, new ProviderDetails(
            nameEn: trim((string) $data['name_en']),
            nameAm: filled($data['name_am'] ?? null) ? trim((string) $data['name_am']) : null,
            contactPerson: $data['contact_person'] ?? null,
            phoneNumber: $data['phone_number'] ?? null,
            email: $data['email'] ?? null,
            address: $data['address'] ?? null,
            status: $data['status'] ?? null,
            metadata: ['settlement_details' => $data['settlement_details'] ?? null],
            actorId: $actor->id,
        ));

        return $provider->refresh();
    }

    public function ensureCafeteriaService(Provider $provider, ?User $actor = null): void
    {
        $this->providers->enableService((string) $provider->id, 'cafeteria', $actor?->id, 'Cafeteria Service');
    }
}
