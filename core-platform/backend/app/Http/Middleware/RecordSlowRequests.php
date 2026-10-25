<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Observability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Notes a request slower than core.slow_request_ms (ADR 0011): its route name or pattern and duration only. */
class RecordSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        if ($ms >= (int) config('core.slow_request_ms')) {
            $route = $request->route();
            Observability::slowRequest((string) ($route?->getName() ?? $route?->uri() ?? 'unmatched'), $request->method(), $ms, $response->getStatusCode());
        }

        return $response;
    }
}
