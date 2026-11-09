<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection;

use App\Services\Intelligence\Analysis\Baseline;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use Carbon\CarbonImmutable;

/**
 * Everything a rule may read for one detection run: the moment, the current
 * five-minute bucket, the baseline window (from settings) and the signal
 * repository. Shared helpers keep the rules short and the arithmetic in one
 * place.
 */
final class DetectionWindow
{
    public const BUCKET_MINUTES = 5;

    public readonly CarbonImmutable $bucketStart;

    public readonly CarbonImmutable $baselineSince;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        public readonly CarbonImmutable $now,
        public readonly SignalRepository $signals,
        public readonly IntelligenceSettings $settings,
    ) {
        $minute = (int) floor($now->minute / self::BUCKET_MINUTES) * self::BUCKET_MINUTES;
        $this->bucketStart = $now->setTime($now->hour, $minute, 0)->subMinutes(self::BUCKET_MINUTES);
        $this->baselineSince = $now->subDays($settings->baselineWindowDays());
    }

    /** The freshest sample no older than `minutes`, or null when nothing recent was collected. */
    public function recent(string $metric, ?string $dimension = null, int $minutes = 15): ?float
    {
        $key = "recent:{$metric}:{$dimension}:{$minutes}";
        if (! array_key_exists($key, $this->memo)) {
            $sample = $this->signals->latest($metric, $dimension, $this->now->subMinutes($minutes));
            $this->memo[$key] = $sample['value'] ?? null;
        }

        return $this->memo[$key];
    }

    /** Baseline values of a metric: the window before the last `excludeMinutes`. @return list<float> */
    public function history(string $metric, ?string $dimension = null, int $excludeMinutes = 30): array
    {
        $key = "history:{$metric}:{$dimension}:{$excludeMinutes}";
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->signals->values($metric, $dimension, $this->baselineSince, $this->now->subMinutes($excludeMinutes));
        }

        return $this->memo[$key];
    }

    /** Sum over the last `minutes`. */
    public function recentSum(string $metric, ?string $dimension, int $minutes): float
    {
        return $this->signals->sum($metric, $dimension, $this->now->subMinutes($minutes));
    }

    /** Whether a value sits `sigma` robust deviations above its history. */
    public function abnormallyHigh(float $value, array $history, int|float $minimum = 0): bool
    {
        return Baseline::isAbnormallyHigh($value, $history, $this->settings->anomalySigma(), $minimum);
    }

    /**
     * Severity from a configured limit and/or the baseline: `critical` at
     * twice the limit, `high` at the limit, `medium` for a baseline-only
     * deviation, null when neither fires. Null limit = baseline only.
     */
    public function severityFor(float $value, ?int $limit, array $history, int|float $minimum = 0): ?string
    {
        if ($limit !== null && $value >= $limit) {
            return $value >= 2 * $limit ? 'critical' : 'high';
        }
        if ($this->abnormallyHigh($value, $history, $minimum)) {
            return 'medium';
        }

        return null;
    }

    public function threshold(string $key): ?int
    {
        return $this->settings->threshold($key);
    }

    /** @return array{label: string, value: string|int|float|null} */
    public static function evidence(string $label, string|int|float|null $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    /** Robust baseline description for evidence rows. @return array{median: ?float, p95: ?float, n: int} */
    public function describe(array $history): array
    {
        $stats = Baseline::describe($history);

        return ['median' => $stats['median'], 'p95' => $stats['p95'], 'n' => $stats['n']];
    }
}
