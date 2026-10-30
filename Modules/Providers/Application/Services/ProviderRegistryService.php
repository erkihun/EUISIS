<?php

declare(strict_types=1);

namespace Modules\Providers\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Entitlements\Contracts\ServiceDeliveryRegistry;
use Modules\Providers\Contracts\Data\NewProvider;
use Modules\Providers\Contracts\Data\ProviderDetails;
use Modules\Providers\Contracts\Data\ProviderOwnerAccount;
use Modules\Providers\Contracts\ProviderRegistry;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderService;
use Modules\Providers\Domain\Models\ProviderType;
use Modules\Providers\Domain\Models\ProviderUser;

/**
 * Provider records written by their owner (moved here from Transport's
 * TransportProviderService and Cafeteria's CafeteriaProviderRegistry).
 */
final class ProviderRegistryService implements ProviderRegistry
{
    public function __construct(private readonly ServiceDeliveryRegistry $serviceDelivery) {}

    public function register(NewProvider $provider, ?ProviderOwnerAccount $owner = null): string
    {
        return DB::transaction(function () use ($provider, $owner): string {
            $type = $provider->typeNameIfMissing === null
                ? ProviderType::query()->where('code', $provider->typeCode)->firstOrFail()
                : ProviderType::query()->firstOrCreate(['code' => $provider->typeCode], ['name_en' => $provider->typeNameIfMissing, 'is_active' => true]);

            $attributes = [
                'provider_code' => $provider->code,
                'provider_type_id' => $type->id,
                'name_en' => $provider->nameEn,
                'name_am' => $provider->nameAm,
                'contact_person' => $provider->contactPerson,
                'phone_number' => $provider->phoneNumber,
                'email' => $provider->email,
                'address' => $provider->address,
                'status' => $provider->status,
                'created_by' => $provider->actorId,
            ];
            // Unset fields keep their column defaults.
            if ($provider->assignedScopeType !== null) {
                $attributes['assigned_scope_type'] = $provider->assignedScopeType;
                $attributes['assigned_organization_id'] = $provider->assignedOrganizationId;
            }
            if ($provider->metadata !== null) {
                $attributes['metadata'] = $provider->metadata;
            }
            if ($provider->stampUpdatedBy) {
                $attributes['updated_by'] = $provider->actorId;
            }
            $record = Provider::query()->create($attributes);

            $this->enableService($record->id, $provider->serviceCode, $provider->actorId, $provider->serviceTypeNameIfMissing);

            if ($owner !== null) {
                // Never a constant fallback: the caller supplies a policy-checked or generated one-time password.
                if (blank($owner->initialPassword)) {
                    throw new \InvalidArgumentException('A provider portal account needs an initial password.');
                }

                ProviderUser::query()->create([
                    'provider_id' => $record->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'username' => $owner->username,
                    'password' => Hash::make($owner->initialPassword),
                    // Someone other than the holder knows this password.
                    'must_change_password' => true,
                    'provider_role' => 'owner',
                    'status' => 'active',
                    'portal_enabled' => true,
                    'created_by' => $provider->actorId,
                ]);
            }

            return (string) $record->id;
        });
    }

    public function enableService(string $providerId, string $serviceCode, ?int $actorId, ?string $serviceTypeNameIfMissing = null): void
    {
        $serviceTypeId = $serviceTypeNameIfMissing === null
            ? $this->serviceDelivery->serviceTypeId($serviceCode)
            : $this->serviceDelivery->ensureServiceType($serviceCode, $serviceTypeNameIfMissing);
        if ($serviceTypeId === null) {
            throw new \DomainException("Unknown service type: {$serviceCode}");
        }

        ProviderService::query()->firstOrCreate(
            ['provider_id' => $providerId, 'service_type_id' => $serviceTypeId],
            ['status' => 'active', 'enabled_at' => now(), 'created_by' => $actorId, 'updated_by' => $actorId],
        );
    }

    public function updateDetails(string $providerId, ProviderDetails $details): void
    {
        $provider = Provider::query()->findOrFail($providerId);
        $provider->fill([
            'name_en' => $details->nameEn,
            'name_am' => $details->nameAm,
            'contact_person' => $details->contactPerson,
            'phone_number' => $details->phoneNumber,
            'email' => $details->email,
            'address' => $details->address,
            'status' => $details->status ?? $provider->status,
            'metadata' => [...($provider->metadata ?? []), ...$details->metadata],
            'updated_by' => $details->actorId,
        ])->save();
    }
}
