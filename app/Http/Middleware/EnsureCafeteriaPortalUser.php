<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards all authenticated provider portal routes.
 * Authenticates via the dedicated cafeteria_provider guard (not the admin web guard).
 * Rejects the request if the provider user is missing, inactive, or portal is disabled,
 * or if the associated cafeteria provider is inactive.
 */
final class EnsureCafeteriaPortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\CafeteriaProviderUser|null $providerUser */
        $providerUser = auth('cafeteria_provider')->user();

        if ($providerUser === null) {
            return redirect()->route('cafeteria.portal.login');
        }

        if (! $providerUser->canLogin()) {
            auth('cafeteria_provider')->logout();

            return redirect()->route('cafeteria.portal.login')
                ->withErrors(['identifier' => __('provider-portal.access_denied')]);
        }

        return $next($request);
    }
}
