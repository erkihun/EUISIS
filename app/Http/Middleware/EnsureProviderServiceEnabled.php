<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProviderServiceEnabled
{
    public function handle(Request $request, Closure $next, string $serviceCode): Response
    {
        /** @var \App\Models\ProviderUser|null $providerUser */
        $providerUser = auth('provider')->user();

        if ($providerUser === null || ! $providerUser->hasService($serviceCode)) {
            abort(403, __('provider-portal.service_access_denied'));
        }

        return $next($request);
    }
}
