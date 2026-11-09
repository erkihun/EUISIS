<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use App\Models\ExternalApplication;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Atomic replay protection for write endpoints (F6).
 *
 * A client sends `Idempotency-Key`. The first request CLAIMS the key by
 * inserting a row under a database unique index on (principal, route, key);
 * a concurrent duplicate loses that insert and never executes the write.
 * When the first request completes, its response is stored on the claim and
 * every later repeat replays it with `Idempotent-Replay: true`. A repeat whose
 * body differs from the original is a 409 conflict, not a silent replay, and
 * a repeat that arrives while the original is still running is a 409 too, so
 * the caller retries after a moment instead of racing it.
 *
 * The principal is the application or the user, never the token, so rotating
 * a token keeps the key's history. A failed (non-2xx) original releases the
 * claim so the caller may retry.
 */
readonly class EnforceIdempotencyKey
{
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return $next($request);
        }
        if (mb_strlen($key) > 128) {
            return ApiError::response($request, 400, 'invalid_idempotency_key', 'Invalid Idempotency-Key.');
        }

        [$principalType, $principalId] = $this->principal($request);
        $route = $request->route()?->uri() ?? $request->path();
        // The stored route is the URI pattern, so the concrete path is part of
        // the request hash: one key sent to /services/cafeteria/… and then to
        // /services/transport/… is a reused key (409), not a replay of the
        // first service's response.
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->except(['_token']), JSON_UNESCAPED_UNICODE));

        $claim = null;
        try {
            $claim = ApiIdempotencyKey::query()->create([
                'principal_type' => $principalType, 'principal_id' => $principalId, 'route' => $route, 'idempotency_key' => $key,
                'request_hash' => $hash, 'status' => ApiIdempotencyKey::STATUS_IN_PROGRESS, 'created_at' => now(), 'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = ApiIdempotencyKey::query()->where(['principal_type' => $principalType, 'principal_id' => $principalId, 'route' => $route, 'idempotency_key' => $key])->first();
            if ($existing === null) {
                return ApiError::response($request, 409, 'idempotency_in_progress', 'A request with this Idempotency-Key is being processed.');
            }
            if ($existing->expires_at->isPast()) {
                $existing->delete();

                return $this->handle($request, $next);
            }
            if ($existing->request_hash !== $hash) {
                return ApiError::response($request, 409, 'idempotency_key_reused', 'This Idempotency-Key was already used with a different request.');
            }
            if ($existing->status !== ApiIdempotencyKey::STATUS_COMPLETED) {
                return ApiError::response($request, 409, 'idempotency_in_progress', 'A request with this Idempotency-Key is being processed.');
            }

            return response()->json($existing->response_body, $existing->response_status)->header('Idempotent-Replay', 'true');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $claim->delete();
            throw $e;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $claim->forceFill([
                'status' => ApiIdempotencyKey::STATUS_COMPLETED,
                'response_status' => $response->getStatusCode(),
                'response_body' => json_decode((string) $response->getContent(), true),
                'completed_at' => now(),
            ])->save();
        } else {
            // A failure is retryable: release the key.
            $claim->delete();
        }

        return $response;
    }

    /** @return array{0: string, 1: string} */
    private function principal(Request $request): array
    {
        $user = $request->user();
        if ($user instanceof ExternalApplication) {
            return ['application', (string) $user->getKey()];
        }
        if ($user !== null) {
            return ['user', (string) $user->getAuthIdentifier()];
        }

        return ['anonymous', hash('sha256', (string) $request->ip())];
    }
}
