<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated provider user has a valid cafeteria provider linked.
 * With the new architecture, the provider is always derived from the provider user's
 * cafeteria_provider_id — no session-based provider selection is needed.
 */
final class EnsureCafeteriaProviderAssigned
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\CafeteriaProviderUser|null $providerUser */
        $providerUser = auth('cafeteria_provider')->user();

        if ($providerUser === null || $providerUser->cafeteriaProvider === null) {
            abort(403, __('provider-portal.not_assigned'));
        }

        return $next($request);
    }
}
