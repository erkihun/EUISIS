<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Providers\Contracts\Data\NewProvider;
use Modules\Providers\Contracts\Data\ProviderOwnerAccount;
use Modules\Providers\Contracts\Data\ProviderRevision;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Providers\Contracts\ProviderRegistry;
use Modules\Transport\Domain\Models\TransportProviderProfile;

/**
 * Registers, changes and archives a transport provider: the provider record,
 * its transport service and optional first portal account through the
 * Providers module's registry, and the transport profile (licence, service
 * area) that Transport owns.
 */
class TransportProviderService
{
    private const PROFILE_FIELDS = ['license_number', 'registration_number', 'service_area_description_en', 'service_area_description_am'];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ProviderDirectory $directory,
    ) {}

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

    /**
     * Only the fields given change: a field the form leaves out keeps its
     * stored value, on the provider record and on the transport profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $providerId, array $data, ?int $actorId = null): void
    {
        if (($data['assigned_scope_type'] ?? null) === 'citywide') {
            $data['assigned_organization_id'] = null;
        }

        DB::transaction(function () use ($providerId, $data, $actorId): void {
            $this->providers->revise($providerId, new ProviderRevision(
                array_intersect_key($data, array_flip(ProviderRevision::FIELDS)),
                $actorId,
            ));

            $profile = TransportProviderProfile::query()->firstOrNew(['provider_id' => $providerId]);
            $profile->fill(array_intersect_key($data, array_flip(self::PROFILE_FIELDS)) + [
                'status' => $data['status'] ?? $profile->status ?? 'active',
                'updated_by' => $actorId,
            ])->save();
        });
    }

    /**
     * Archives the provider unless it also runs another service (a cafeteria):
     * that provider is not transport's to archive, since its other portal
     * users would be locked out. Returns whether it was archived.
     */
    public function archive(string $providerId): bool
    {
        if (array_diff($this->directory->activeServiceCodes($providerId), ['transport']) !== []) {
            return false;
        }

        $this->providers->archive($providerId);

        return true;
    }
}
