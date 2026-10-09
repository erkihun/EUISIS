<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ProviderPortal\ProviderPortalContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
