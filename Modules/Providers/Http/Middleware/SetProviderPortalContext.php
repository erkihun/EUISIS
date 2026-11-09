<?php

declare(strict_types=1);

namespace Modules\Providers\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Providers\Application\Services\ProviderPortalContext;
use Symfony\Component\HttpFoundation\Response;

class SetProviderPortalContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = app(ProviderPortalContext::class)->providerPayload($request);

        Inertia::share('providerPortal', $payload['providerPortal']);

        return $next($request);
    }
}
