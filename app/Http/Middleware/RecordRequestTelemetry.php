<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\Telemetry\RequestCounters;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts every HTTP response by route group, status class and latency bucket
 * in the cache (RequestCounters) after the response is sent. It adds no query
 * to the request, carries no URL, identifier or person, and switches off with
 * System Settings › Intelligence › Platform Telemetry.
 */
final class RecordRequestTelemetry
{
    public function __construct(
        private readonly RequestCounters $counters,
        private readonly IntelligenceSettings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->settings->telemetryEnabled()) {
                return;
            }
            $started = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) ($request->server('REQUEST_TIME_FLOAT') ?? microtime(true));
            $durationMs = max(0.0, (microtime(true) - $started) * 1000);
            $route = $request->route();
            $routeName = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;

            $this->counters->record(RouteGroups::of($routeName, $request->getPathInfo()), $response->getStatusCode(), $durationMs);
        } catch (Throwable) {
            // Telemetry never breaks a request.
        }
    }
}
