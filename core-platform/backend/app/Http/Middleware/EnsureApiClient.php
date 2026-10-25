<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * An integration client's call: the token must belong to an API client that
 * is active, not revoked or expired, calling from an allowed address, holding
 * the route's scope (on the client and on the token), within its own rate
 * limit. Every call of an identified client is logged without its payload.
 * Errors follow the API error contract: {message, code, errors}.
 */
class EnsureApiClient
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $started = hrtime(true);
        $client = $request->user();
        if (! $client instanceof ApiClient) {
            return self::error(403, 'NOT_AN_API_CLIENT', 'This route is for integration clients.');
        }

        $denial = $client->denial($request->ip());
        if ($denial === null && (! $client->hasScope($scope) || ! $client->currentAccessToken()?->can($scope))) {
            $denial = 'SCOPE_DENIED';
        }
        $key = 'api-client:'.$client->id;
        if ($denial === null && RateLimiter::tooManyAttempts($key, max(1, $client->rate_limit_per_minute))) {
            $denial = 'RATE_LIMITED';
        }

        if ($denial !== null) {
            $response = $denial === 'RATE_LIMITED'
                ? self::error(429, $denial, 'Too many requests for this client.')->header('Retry-After', (string) RateLimiter::availableIn($key))
                : self::error(403, $denial, 'This client may not make this call.');
        } else {
            RateLimiter::hit($key, 60);
            $request->attributes->set('api_client', $client);
            $response = $next($request);
        }

        $this->log($client, $request, $response, $started, $denial);

        return $response;
    }

    public static function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code, 'errors' => (object) []], $status);
    }

    private function log(ApiClient $client, Request $request, Response $response, int $started, ?string $denial): void
    {
        $body = $response instanceof JsonResponse ? $response->getData(true) : null;
        ApiRequestLog::query()->create([
            'api_client_id' => $client->id,
            'method' => $request->method(),
            'route' => mb_substr('/'.ltrim((string) ($request->route()?->uri() ?? $request->path()), '/'), 0, 160),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'ip' => $request->ip(),
            'request_id' => $request->attributes->get('request_id'),
            'denial_code' => $denial ?? (is_array($body) && $response->getStatusCode() >= 400 ? mb_substr((string) ($body['code'] ?? ''), 0, 40) ?: null : null),
            'created_at' => now(),
        ]);
        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
    }
}
