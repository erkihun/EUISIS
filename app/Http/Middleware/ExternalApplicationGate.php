<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use App\Models\ExternalApplication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-application gate for the integration API.
 *
 * Applies to callers authenticated as an ExternalApplication:
 *   1. the application must be active,
 *   2. the source IP must satisfy its allowlist (empty = unrestricted),
 *   3. its own rate_limit_per_minute is enforced,
 *   4. the request is logged (metadata only — never bodies).
 *
 * Requests from human users pass straight through; they are governed by the
 * usual session policies rather than application registration.
 */
readonly class ExternalApplicationGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->user();

        if (! $application instanceof ExternalApplication) {
            return $next($request);
        }

        if (! $application->isActive()) {
            return $this->deny($request, $application, 403, 'application_'.$application->status);
        }

        if (! $application->allowsIp($request->ip())) {
            return $this->deny($request, $application, 403, 'ip_not_allowed');
        }

        // Endpoint assignment. Checked before the rate limiter so a call the
        // application may never make cannot consume its quota.
        if (! $application->allowsEndpoint($request->method(), $this->routeUri($request))) {
            return $this->deny($request, $application, 403, 'endpoint_not_allowed');
        }

        $limit = max(1, $application->rate_limit_per_minute);
        $key = 'external-app:'.$application->getKey();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $response = $this->deny($request, $application, 429, 'rate_limit_exceeded');
            $response->headers->set('Retry-After', (string) RateLimiter::availableIn($key));

            return $response;
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        $this->log(
            $request,
            $application,
            $response->getStatusCode(),
            $response->getStatusCode() < 400,
            $response->getStatusCode() < 400 ? null : 'request_failed',
        );

        $application->forceFill(['last_used_at' => now()])->saveQuietly();

        return $response;
    }

    /**
     * The matched route's URI pattern, which is what the catalog stores.
     *
     * Comparing against $request->path() would never match a parameterised
     * endpoint, since the catalog holds `/api/v1/employees/{employee}/...`
     * while the path carries a concrete id.
     */
    private function routeUri(Request $request): string
    {
        $uri = $request->route()?->uri() ?? $request->path();

        return '/'.ltrim($uri, '/');
    }

    private function deny(Request $request, ExternalApplication $application, int $status, string $reason): Response
    {
        $this->log($request, $application, $status, false, $reason);

        return response()->json([
            'message' => $status === 429 ? 'Too Many Requests.' : 'Forbidden.',
            'error_code' => $reason,
        ], $status);
    }

    private function log(
        Request $request,
        ExternalApplication $application,
        int $status,
        bool $success,
        ?string $failureReason,
    ): void {
        ApiRequestLog::query()->create([
            'external_application_id' => $application->getKey(),
            'endpoint' => '/'.ltrim($request->path(), '/'),
            'method' => $request->method(),
            'ip_address' => $request->ip(),
            'status_code' => $status,
            'success' => $success,
            'failure_reason' => $failureReason,
            'requested_at' => now(),
            // Route name only — no query string, headers or body.
            'metadata' => ['route' => $request->route()?->getName()],
        ]);
    }
}
