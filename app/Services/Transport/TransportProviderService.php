<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\ProviderType;
use App\Models\ProviderUser;
use App\Models\ServiceType;
use App\Models\TransportProviderProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TransportProviderService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $actorId = null): Provider
    {
        return DB::transaction(function () use ($data, $actorId): Provider {
            $providerType = ProviderType::query()->where('code', 'TRANSPORT')->firstOrFail();
            $serviceType = ServiceType::query()->where('code', 'transport')->firstOrFail();

            $provider = Provider::query()->create([
                'provider_code' => $data['provider_code'],
                'provider_type_id' => $providerType->id,
                'name_en' => $data['name_en'],
                'name_am' => $data['name_am'] ?? null,
                'assigned_scope_type' => $data['assigned_scope_type'] ?? 'self',
                'assigned_organization_id' => ($data['assigned_scope_type'] ?? 'self') === 'citywide'
                    ? null
                    : ($data['assigned_organization_id'] ?? null),
                'contact_person' => $data['contact_person'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => $data['status'] ?? 'active',
                'created_by' => $actorId,
            ]);

            ProviderService::query()->create([
                'provider_id' => $provider->id,
                'service_type_id' => $serviceType->id,
                'status' => 'active',
                'enabled_at' => now(),
                'created_by' => $actorId,
            ]);

            TransportProviderProfile::query()->create([
                'provider_id' => $provider->id,
                'license_number' => $data['license_number'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'dispatch_phone' => $data['phone_number'] ?? null,
                'service_area_description_en' => $data['service_area_description_en'] ?? null,
                'service_area_description_am' => $data['service_area_description_am'] ?? null,
                'status' => $data['status'] ?? 'active',
                'created_by' => $actorId,
            ]);

            if (($data['create_provider_user'] ?? false) && ! empty($data['user_name']) && (! empty($data['user_email']) || ! empty($data['username']))) {
                // Never a constant fallback: the controller always supplies a
                // policy-checked or generated one-time password.
                if (blank($data['user_password'] ?? null)) {
                    throw new \InvalidArgumentException('A provider portal account needs an initial password.');
                }

                ProviderUser::query()->create([
                    'provider_id' => $provider->id,
                    'name' => $data['user_name'],
                    'email' => $data['user_email'] ?? null,
                    'username' => $data['username'] ?? null,
                    'password' => Hash::make((string) $data['user_password']),
                    // Someone other than the holder knows this password.
                    'must_change_password' => true,
                    'provider_role' => 'owner',
                    'status' => 'active',
                    'portal_enabled' => true,
                    'created_by' => $actorId,
                ]);
            }

            return $provider->load(['transportProfile', 'services.serviceType', 'users']);
        });
    }
}
