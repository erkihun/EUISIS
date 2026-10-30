<?php

declare(strict_types=1);

namespace Modules\Providers\Application\Queries;

use App\Support\Features;
use Modules\Providers\Contracts\Data\ProviderSummary;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Providers\Domain\Models\Provider;

final class ProviderDirectoryQuery implements ProviderDirectory
{
    public function offering(string $serviceCode, bool $activeOnly = false): array
    {
        return Provider::query()
            ->whereHas('services.serviceType', fn ($query) => $query->where('code', $serviceCode))
            ->when($activeOnly, fn ($query) => $query->where('status', 'active'))
            ->orderBy('name_en')
            ->get(['id', 'provider_code', 'name_en', 'name_am'])
            ->map(fn (Provider $provider): array => [
                'id' => (string) $provider->id,
                'provider_code' => $provider->provider_code,
                'name_en' => $provider->name_en,
                'name_am' => $provider->name_am,
            ])
            ->values()
            ->all();
    }

    public function hasService(string $providerId, string $serviceCode): bool
    {
        // Provider::hasService in one query; archived providers are not found.
        if (! Features::serviceAvailable($serviceCode)) {
            return false;
        }

        return Provider::query()
            ->whereKey($providerId)
            ->whereHas('services', fn ($query) => $query->where('status', 'active')
                ->whereHas('serviceType', fn ($type) => $type->where('code', $serviceCode)->where('is_active', true)))
            ->exists();
    }

    public function find(string $providerId): ?ProviderSummary
    {
        $provider = Provider::query()->find($providerId, ['id', 'provider_code', 'name_en', 'name_am', 'status']);

        return $provider === null ? null : new ProviderSummary(
            id: (string) $provider->id,
            code: (string) $provider->provider_code,
            nameEn: (string) $provider->name_en,
            nameAm: $provider->name_am,
            status: (string) $provider->status,
        );
    }
}
