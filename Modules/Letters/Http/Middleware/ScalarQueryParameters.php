<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every filter the Letters pages read from the address is a single value
 * (`?q=…`, `?tab=…`, `?organization_id=…`). A hand-made or crawled address
 * that sends one as a list (`?q[]=x`) used to reach a string conversion and
 * answer 500; here such a parameter is dropped, so the page opens as if it
 * had not been given. Request bodies are not touched: forms validate theirs.
 */
final class ScalarQueryParameters
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($request->query->all() as $key => $value) {
            if (is_array($value)) {
                $request->query->remove($key);
            }
        }

        return $next($request);
    }
}
