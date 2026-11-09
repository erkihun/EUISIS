<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for aggregate payloads.
 *
 * The key holds the part, the scope fingerprint (which organizations), the
 * filter fingerprint and the detail grants — never just the user — so two
 * viewers share an entry only when they would see exactly the same thing, and
 * different scopes never share one. Every entry records when it was computed;
 * the page shows it, so cached data is never passed off as live.
 */
final class SituationCache
{
    private const VERSION = 'v1';

    public function __construct(private readonly SituationSettings $settings) {}

    /**
     * The cached payload, or a fresh one. A payload `$keep` rejects (one with
     * a failed panel) is returned but not stored, so a passing failure is
     * retried on the next request instead of being served for minutes.
     *
     * @param  callable(): array<string, mixed>  $compute
     * @param  (callable(array<string, mixed>): bool)|null  $keep
     * @return array{generated_at: string, cached_for: int, payload: array<string, mixed>}
     */
    public function remember(string $part, SituationContext $context, callable $compute, ?callable $keep = null): array
    {
        $ttl = $this->settings->cacheSeconds();
        $key = implode(':', [
            'situation-room', self::VERSION, $part,
            $context->scope->fingerprint(),
            $context->filters->fingerprint(),
            $context->grantsFingerprint(),
            $context->user->employee_id === null ? 'x' : 'e:'.$context->user->employee_id,
        ]);

        if ($ttl <= 0) {
            return $this->fresh($ttl, $compute);
        }

        // Stale-while-revalidate: city-wide workforce aggregates take several
        // seconds of database time at 3M employees
        // (docs/production-readiness/database-capacity.md). An entry past its
        // TTL is still served — it carries its own generated_at — while one
        // refresh runs after the response, so expiry never makes every open
        // wall screen recompute at once.
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $age = CarbonImmutable::now()->getTimestamp() - CarbonImmutable::parse($cached['generated_at'])->getTimestamp();
            if ($age >= $ttl) {
                defer(fn () => Cache::lock($key.':refresh', 300)->get(fn () => $this->store($key, $ttl, $this->fresh($ttl, $compute), $keep)), $key);
            }

            return $cached;
        }

        // Cold: one viewer computes while the others with the same key wait
        // briefly for the result instead of running the same queries.
        $entry = Cache::lock($key.':refresh', 300)->get(fn () => $this->store($key, $ttl, $this->fresh($ttl, $compute), $keep));
        if ($entry === false) {
            for ($waited = 0; $waited < 20 && ! is_array($entry = Cache::get($key)); $waited++) {
                usleep(250_000);
            }
            $entry = is_array($entry) ? $entry : $this->fresh($ttl, $compute);
        }

        return $entry;
    }

    /** @return array{generated_at: string, cached_for: int, payload: array<string, mixed>} */
    private function fresh(int $ttl, callable $compute): array
    {
        return ['generated_at' => CarbonImmutable::now()->toIso8601String(), 'cached_for' => $ttl, 'payload' => $compute()];
    }

    /**
     * Keeps the entry for its TTL plus a stale window (5× the TTL, at least 10
     * minutes) in which it may still be served while refreshed.
     *
     * @param  array{generated_at: string, cached_for: int, payload: array<string, mixed>}  $entry
     * @return array{generated_at: string, cached_for: int, payload: array<string, mixed>}
     */
    private function store(string $key, int $ttl, array $entry, ?callable $keep): array
    {
        if ($keep === null || $keep($entry['payload'])) {
            Cache::put($key, $entry, $ttl + max(600, $ttl * 5));
        }

        return $entry;
    }
}
