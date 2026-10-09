<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ProviderPortal\ProviderPortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProviderHasActiveAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = app(ProviderPortalContext::class)->selectedProvider($request);

        if ($provider === null) {
            abort(403, __('provider-portal.not_assigned'));
        }

        return $next($request);
    }
}
