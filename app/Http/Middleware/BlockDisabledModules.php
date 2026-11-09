<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 for every route of a module whose feature flag is off, so an
 * out-of-scope module (e.g. Transport) is not reachable in production even
 * by a user who still holds its permissions.
 */
class BlockDisabledModules
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Features::disabledModuleForRoute($request->route()?->getName()) !== null, 404);

        return $next($request);
    }
}
