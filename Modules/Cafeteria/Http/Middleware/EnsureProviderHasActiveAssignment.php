<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cafeteria\Application\Services\Portal\CafeteriaPortalSelector;
use Symfony\Component\HttpFoundation\Response;

class EnsureProviderHasActiveAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);

        if ($provider === null) {
            abort(403, __('provider-portal.not_assigned'));
        }

        return $next($request);
    }
}
