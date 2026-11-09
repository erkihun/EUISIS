<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI\Providers;

/**
 * TLS verification for provider calls: always on. On hosts whose PHP has no
 * system certificate store (Windows builds), `INTELLIGENCE_AI_CA_BUNDLE`
 * names the bundle to trust; anywhere else PHP's defaults apply. Verification
 * is never switched off.
 */
trait VerifiesTls
{
    protected static function verify(): bool|string
    {
        $bundle = (string) config('intelligence.ai.ca_bundle', '');

        return $bundle !== '' && is_file($bundle) ? $bundle : true;
    }
}
