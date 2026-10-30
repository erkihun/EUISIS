<?php

declare(strict_types=1);

namespace Modules\Providers\Application\Services;

use App\Backbone\Contracts\ProviderCodeLookup;
use Modules\Providers\Domain\Models\ServiceProvider;

/** Service delivery's side of the backbone's ProviderCodeLookup contract. */
final class ServiceProviderCodeLookup implements ProviderCodeLookup
{
    public function codeFor(string $providerId): ?string
    {
        $code = ServiceProvider::query()->whereKey($providerId)->value('code');

        return $code === null ? null : (string) $code;
    }
}
