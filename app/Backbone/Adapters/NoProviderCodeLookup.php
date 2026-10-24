<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\ProviderCodeLookup;

/** The default when no provider module is installed: no provider is known. */
final class NoProviderCodeLookup implements ProviderCodeLookup
{
    public function codeFor(string $providerId): ?string
    {
        return null;
    }
}
