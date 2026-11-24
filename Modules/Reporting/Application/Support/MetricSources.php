<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use Illuminate\Support\Facades\Log;
use Modules\Reporting\Application\Ports\MetricSource;
use Throwable;

/**
 * The optional indicator sources that are bound. None is required: with no
 * source, or with one that fails, capture() simply returns less.
 */
final class MetricSources
{
    /** @param iterable<MetricSource> $sources */
    public function __construct(private readonly iterable $sources = []) {}

    /**
     * @param  list<string>  $organizationIds
     * @return list<array{source: string, metric_key: string, definition: string, value: float|int|null, unit: ?string}>
     */
    public function capture(array $organizationIds, string $periodStart, string $periodEnd): array
    {
        $values = [];
        foreach ($this->sources as $source) {
            try {
                if (! $source->isAvailable()) {
                    continue;
                }
                foreach ($source->capture($organizationIds, $periodStart, $periodEnd) as $metric) {
                    $values[] = ['source' => $source->key(), ...$metric];
                }
            } catch (Throwable $e) {
                // An indicator provider being down must never stop narrative reporting.
                Log::warning('reporting: metric source skipped', ['source' => $source::class, 'error' => $e->getMessage()]);
            }
        }

        return $values;
    }

    /** @return list<array{key: string, available: bool}> for the settings page */
    public function status(): array
    {
        $status = [];
        foreach ($this->sources as $source) {
            try {
                $status[] = ['key' => $source->key(), 'available' => $source->isAvailable()];
            } catch (Throwable) {
                $status[] = ['key' => $source::class, 'available' => false];
            }
        }

        return $status;
    }
}
