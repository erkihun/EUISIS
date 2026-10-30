<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Providers\Contracts\Data\NewProvider;
use Modules\Providers\Contracts\Data\ProviderOwnerAccount;
use Modules\Providers\Contracts\ProviderRegistry;
use Modules\Transport\Domain\Models\TransportProviderProfile;

/**
 * Registers a transport provider: the provider record, its transport service
 * and optional first portal account through the Providers module's registry,
 * and the transport profile (licence, service area) that Transport owns.
 */
class TransportProviderService
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    /**
     * @param  array<string, mixed>  $data
     * @return string the new provider's id
     */
    public function create(array $data, ?int $actorId = null): string
    {
        return DB::transaction(function () use ($data, $actorId): string {
            $scope = $data['assigned_scope_type'] ?? 'self';
            $owner = null;
            if (($data['create_provider_user'] ?? false) && ! empty($data['user_name']) && (! empty($data['user_email']) || ! empty($data['username']))) {
                // Never a constant fallback: the controller always supplies a
                // policy-checked or generated one-time password.
                if (blank($data['user_password'] ?? null)) {
                    throw new \InvalidArgumentException('A provider portal account needs an initial password.');
                }
                $owner = new ProviderOwnerAccount($data['user_name'], $data['user_email'] ?? null, $data['username'] ?? null, (string) $data['user_password']);
            }

            $providerId = $this->providers->register(new NewProvider(
                code: $data['provider_code'],
                nameEn: $data['name_en'],
                nameAm: $data['name_am'] ?? null,
                typeCode: 'TRANSPORT',
                typeNameIfMissing: null,
                serviceCode: 'transport',
                contactPerson: $data['contact_person'] ?? null,
                phoneNumber: $data['phone_number'] ?? null,
                email: $data['email'] ?? null,
                address: $data['address'] ?? null,
                status: $data['status'] ?? 'active',
                assignedScopeType: $scope,
                assignedOrganizationId: $scope === 'citywide' ? null : ($data['assigned_organization_id'] ?? null),
                actorId: $actorId,
            ), $owner);

            TransportProviderProfile::query()->create([
                'provider_id' => $providerId,
                'license_number' => $data['license_number'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'dispatch_phone' => $data['phone_number'] ?? null,
                'service_area_description_en' => $data['service_area_description_en'] ?? null,
                'service_area_description_am' => $data['service_area_description_am'] ?? null,
                'status' => $data['status'] ?? 'active',
                'created_by' => $actorId,
            ]);

            return $providerId;
        });
    }
}
