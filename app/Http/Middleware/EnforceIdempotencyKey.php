<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay protection for write endpoints.
 *
 * A client may send `Idempotency-Key`; the first successful response for that
 * key is stored and replayed for any repeat with the same key, so a retried
 * request after a network timeout cannot record a second transaction.
 *
 * The key is namespaced per authenticated token, so two applications cannot
 * collide — or probe — each other's keys.
 */
readonly class EnforceIdempotencyKey
{
    /** How long a response stays replayable. */
    private const TTL_MINUTES = 1440;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            return $next($request);
        }

        if (mb_strlen($key) > 128) {
            return response()->json([
                'message' => 'Invalid Idempotency-Key.',
                'error_code' => 'invalid_idempotency_key',
            ], Response::HTTP_BAD_REQUEST);
        }

        $cacheKey = $this->cacheKey($request, $key);
        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            return response()
                ->json($stored['body'], $stored['status'])
                ->header('Idempotent-Replay', 'true');
        }

        $response = $next($request);

        // Only successful writes are replayable; a failure should be retryable.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => json_decode((string) $response->getContent(), true),
            ], now()->addMinutes(self::TTL_MINUTES));
        }

        return $response;
    }

    private function cacheKey(Request $request, string $key): string
    {
        $tokenId = $request->user()?->currentAccessToken()?->getKey() ?? 'session';

        return 'idempotency:'.$tokenId.':'.hash('sha256', $request->path().'|'.$key);
    }
}
