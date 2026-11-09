<?php

declare(strict_types=1);

namespace App\Services\Integration\Webhooks;

/**
 * Outcome of one delivery attempt and when to try again.
 *
 *   2xx                         succeeded
 *   408, 425, 429, 5xx,
 *   network error, timeout      retry with backoff
 *   3xx                         permanent (redirects are never followed)
 *   400, 401, 403, 404, 410,
 *   413, 422 and other 4xx      permanent — needs partner intervention;
 *                               the event stays replayable
 *
 * Backoff before attempt n: ceiling = min(max_delay, base · 2^(n−2)), then a
 * random delay between ceiling/2 and ceiling (equal jitter) so partners
 * recovering from an outage are not hit by synchronized retries. At most
 * max_attempts attempts — never forever.
 */
final class RetryPolicy
{
    public const SUCCEEDED = 'succeeded';

    public const RETRY = 'retry';

    public const PERMANENT = 'permanent';

    /** @return array{0: string, 1: string|null} [outcome, error_category] */
    public function classify(?int $status, ?string $transportError = null): array
    {
        if ($transportError !== null) {
            return [self::RETRY, $transportError];
        }

        return match (true) {
            $status === null => [self::RETRY, 'no_response'],
            $status >= 200 && $status < 300 => [self::SUCCEEDED, null],
            in_array($status, [408, 425, 429], true) => [self::RETRY, $status === 429 ? 'rate_limited' : 'timeout_status'],
            $status >= 500 => [self::RETRY, 'server_error'],
            $status >= 300 && $status < 400 => [self::PERMANENT, 'redirect_refused'],
            in_array($status, [401, 403], true) => [self::PERMANENT, 'partner_auth_rejected'],
            in_array($status, [404, 410], true) => [self::PERMANENT, 'endpoint_not_found'],
            default => [self::PERMANENT, 'partner_rejected'],
        };
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('integration.webhooks.max_attempts', 8));
    }

    /** Seconds before attempt number $nextAttempt (2, 3, …). */
    public function delaySeconds(int $nextAttempt): int
    {
        $base = max(1, (int) config('integration.webhooks.base_delay_seconds', 30));
        $max = max($base, (int) config('integration.webhooks.max_delay_seconds', 3600));
        $ceiling = (int) min($max, $base * (2 ** max(0, $nextAttempt - 2)));

        // Equal jitter, never immediate.
        return random_int(max(1, intdiv($ceiling, 2)), max(1, $ceiling));
    }
}
