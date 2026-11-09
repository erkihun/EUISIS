<?php

declare(strict_types=1);

namespace Modules\Performance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Performance\Application\Services\EpmsSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * The EPMS kill switch (`performance.enabled`) applied to every performance
 * route, so switching the module off also stops its write actions, not only
 * the pages that happened to check it. Settings stay reachable so it can be
 * switched back on.
 */
class EnsurePerformanceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('performance.settings.*') && ! app(EpmsSettings::class)->enabled()) {
            abort(404);
        }

        return $next($request);
    }
}
