<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

/**
 * The business code of a service provider, for code-rule tokens such as
 * {PROVIDER_CODE}. Providers belong to service delivery; the code generator
 * asks through this contract instead of reading the provider table.
 */
interface ProviderCodeLookup
{
    /** The provider's code, or null when the provider does not exist. */
    public function codeFor(string $providerId): ?string;
}
