<?php

declare(strict_types=1);

namespace App\Support\ProviderPortal;

use App\Models\Provider;

/**
 * The per-service permissions an OPERATOR account can be granted — only the
 * ones the provider portal actually enforces (canUseServicePermission).
 * Owners and managers hold all of them implicitly. Cafeteria pages follow the
 * provider's cafeteria service and need no per-user key.
 *
 * These are provider-portal keys (provider_user_service_permissions), not
 * staff permissions, so they are not part of the RBAC PermissionCatalog.
 */
final class ProviderUserPermissionCatalog
{
    public const ROLES = ['owner', 'manager', 'operator'];

    /** @var array<string, list<string>> service code => permission keys */
    public const KEYS_BY_SERVICE = [
        'transport' => [
            'provider.transport.scan',
            'provider.transport.routes.manage',
            'provider.transport.vehicles.manage',
            'provider.transport.drivers.manage',
            'provider.transport.trips.manage',
            'provider.transport.reports.view',
        ],
    ];

    /** @return list<string> keys offered for the provider's active services */
    public static function forProvider(Provider $provider): array
    {
        return self::forServices(self::activeServiceCodes($provider));
    }

    /**
     * @param  iterable<string>  $serviceCodes
     * @return list<string>
     */
    public static function forServices(iterable $serviceCodes): array
    {
        $keys = [];
        foreach ($serviceCodes as $code) {
            $keys = [...$keys, ...(self::KEYS_BY_SERVICE[$code] ?? [])];
        }

        return $keys;
    }

    /** @return list<string> every key the catalog knows */
    public static function all(): array
    {
        return self::forServices(array_keys(self::KEYS_BY_SERVICE));
    }

    /** @return list<string> */
    public static function activeServiceCodes(Provider $provider): array
    {
        $services = $provider->relationLoaded('services')
            ? $provider->services->where('status', 'active')
            : $provider->services()->where('status', 'active')->with('serviceType:id,code')->get();

        return $services->pluck('serviceType.code')->filter()->unique()->values()->all();
    }

    public static function serviceOf(string $key): ?string
    {
        foreach (self::KEYS_BY_SERVICE as $service => $keys) {
            if (in_array($key, $keys, true)) {
                return $service;
            }
        }

        return null;
    }
}
