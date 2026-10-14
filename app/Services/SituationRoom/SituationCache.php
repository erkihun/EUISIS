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

        if ($ttl > 0 && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $entry = [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'cached_for' => $ttl,
            'payload' => $compute(),
        ];

        if ($ttl > 0 && ($keep === null || $keep($entry['payload']))) {
            Cache::put($key, $entry, $ttl);
        }

        return $entry;
    }
}
