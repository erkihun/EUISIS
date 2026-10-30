<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts;

use Modules\Providers\Contracts\Data\ProviderSummary;

/**
 * Read access to provider records for other modules: pickers, existence and
 * service checks, and the few fields a record needs (code, names, status).
 * Archived providers are not found.
 */
interface ProviderDirectory
{
    /**
     * Providers that carry the service (in any status of the service), by
     * English name, as picker rows.
     *
     * @return list<array{id: string, provider_code: string, name_en: string, name_am: ?string}>
     */
    public function offering(string $serviceCode, bool $activeOnly = false): array;

    /** Whether the provider runs the service: the module is enabled and the provider's service is active. */
    public function hasService(string $providerId, string $serviceCode): bool;

    public function find(string $providerId): ?ProviderSummary;
}
