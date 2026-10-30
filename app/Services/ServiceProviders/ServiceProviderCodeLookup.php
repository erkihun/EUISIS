<?php

declare(strict_types=1);

namespace App\Services\ServiceProviders;

use App\Backbone\Contracts\ProviderCodeLookup;
use App\Models\ServiceProvider;

/** Service delivery's side of the backbone's ProviderCodeLookup contract. */
final class ServiceProviderCodeLookup implements ProviderCodeLookup
{
    public function codeFor(string $providerId): ?string
    {
        $code = ServiceProvider::query()->whereKey($providerId)->value('code');

        return $code === null ? null : (string) $code;
    }
}
