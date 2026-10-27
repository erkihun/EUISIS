<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Network;

use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\ProviderType;
use App\Models\ServiceType;
use App\Models\User;

/**
 * Providers as payees: the organization that operates cafeterias and is paid
 * for their service. A cafeteria provider always carries the `cafeteria`
 * service, which the provider portal requires to open its cafeteria pages.
 */
class CafeteriaProviderRegistry
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Provider
    {
        $provider = Provider::query()->create([
            'provider_code' => strtoupper(trim((string) $data['provider_code'])),
            'provider_type_id' => ProviderType::query()->firstOrCreate(['code' => 'CAFETERIA'], ['name_en' => 'Cafeteria', 'is_active' => true])->id,
            'name_en' => trim((string) $data['name_en']),
            'name_am' => filled($data['name_am'] ?? null) ? trim((string) $data['name_am']) : null,
            'contact_person' => $data['contact_person'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'status' => $data['status'] ?? 'active',
            'metadata' => array_filter(['settlement_details' => $data['settlement_details'] ?? null]),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->ensureCafeteriaService($provider, $actor);

        return $provider;
    }

    /** @param array<string, mixed> $data */
    public function update(Provider $provider, array $data, User $actor): Provider
    {
        $provider->fill([
            'name_en' => trim((string) $data['name_en']),
            'name_am' => filled($data['name_am'] ?? null) ? trim((string) $data['name_am']) : null,
            'contact_person' => $data['contact_person'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'status' => $data['status'] ?? $provider->status,
            'metadata' => [...($provider->metadata ?? []), 'settlement_details' => $data['settlement_details'] ?? null],
            'updated_by' => $actor->id,
        ])->save();

        return $provider;
    }

    public function ensureCafeteriaService(Provider $provider, ?User $actor = null): void
    {
        $serviceType = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria Service', 'is_active' => true]);

        ProviderService::query()->firstOrCreate(
            ['provider_id' => $provider->id, 'service_type_id' => $serviceType->id],
            ['status' => 'active', 'enabled_at' => now(), 'created_by' => $actor?->id, 'updated_by' => $actor?->id],
        );
    }
}
