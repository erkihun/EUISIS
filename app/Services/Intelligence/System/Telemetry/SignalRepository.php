<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Telemetry;

use App\Models\IntelligenceSignal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads and writes intelligence_signals. Readers get compact series, never
 * rows by the thousand: the detection rules, the health analyses and the
 * baselines all come through here.
 */
final class SignalRepository
{
    /** @param  list<array{metric: string, component: string, dimension?: ?string, value: int|float, unit?: ?string, sample_count?: int, bucket_start: CarbonImmutable}>  $samples */
    public function write(array $samples): int
    {
        if ($samples === []) {
            return 0;
        }
        $now = now()->toDateTimeString();
        $rows = array_map(fn (array $s): array => [
            'id' => (string) Str::uuid7(),
            'metric' => $s['metric'],
            'component' => $s['component'],
            'dimension' => $s['dimension'] ?? null,
            'value' => round((float) $s['value'], 4),
            'unit' => $s['unit'] ?? null,
            'sample_count' => $s['sample_count'] ?? 1,
            'bucket_start' => $s['bucket_start']->toDateTimeString(),
            'created_at' => $now,
        ], $samples);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('intelligence_signals')->insert($chunk);
        }

        return count($rows);
    }

    /** The most recent sample of a metric (optionally one dimension). @return array{value: float, bucket_start: CarbonImmutable, sample_count: int}|null */
    public function latest(string $metric, ?string $dimension = null, ?CarbonImmutable $notBefore = null): ?array
    {
        $query = IntelligenceSignal::query()->where('metric', $metric)->orderByDesc('bucket_start');
        $dimension === null ? $query->whereNull('dimension') : $query->where('dimension', $dimension);
        if ($notBefore !== null) {
            $query->where('bucket_start', '>=', $notBefore->toDateTimeString());
        }
        $row = $query->first(['value', 'bucket_start', 'sample_count']);

        return $row === null ? null : ['value' => (float) $row->value, 'bucket_start' => CarbonImmutable::parse($row->bucket_start), 'sample_count' => (int) $row->sample_count];
    }

    /**
     * Values of a metric since a moment, oldest first.
     *
     * @return list<array{bucket_start: string, value: float, sample_count: int}>
     */
    public function series(string $metric, ?string $dimension, CarbonImmutable $since, ?CarbonImmutable $until = null, int $limit = 2000): array
    {
        $query = IntelligenceSignal::query()->where('metric', $metric)->where('bucket_start', '>=', $since->toDateTimeString());
        $dimension === null ? $query->whereNull('dimension') : $query->where('dimension', $dimension);
        if ($until !== null) {
            $query->where('bucket_start', '<', $until->toDateTimeString());
        }

        return $query->orderBy('bucket_start')->limit($limit)->get(['bucket_start', 'value', 'sample_count'])
            ->map(fn (IntelligenceSignal $s): array => ['bucket_start' => $s->bucket_start->toIso8601String(), 'value' => (float) $s->value, 'sample_count' => (int) $s->sample_count])
            ->all();
    }

    /** @return list<float> */
    public function values(string $metric, ?string $dimension, CarbonImmutable $since, ?CarbonImmutable $until = null): array
    {
        return array_map(fn (array $s): float => $s['value'], $this->series($metric, $dimension, $since, $until));
    }

    /** Sum of a metric over a window (counts). */
    public function sum(string $metric, ?string $dimension, CarbonImmutable $since, ?CarbonImmutable $until = null): float
    {
        $query = IntelligenceSignal::query()->where('metric', $metric)->where('bucket_start', '>=', $since->toDateTimeString());
        $dimension === null ? $query->whereNull('dimension') : $query->where('dimension', $dimension);
        if ($until !== null) {
            $query->where('bucket_start', '<', $until->toDateTimeString());
        }

        return (float) $query->sum('value');
    }

    /** Sums per dimension over a window. @return array<string, float> */
    public function sumByDimension(string $metric, CarbonImmutable $since, ?CarbonImmutable $until = null): array
    {
        $query = IntelligenceSignal::query()->where('metric', $metric)->where('bucket_start', '>=', $since->toDateTimeString());
        if ($until !== null) {
            $query->where('bucket_start', '<', $until->toDateTimeString());
        }

        return $query->selectRaw('dimension, SUM(value) AS total')->groupBy('dimension')->pluck('total', 'dimension')
            ->mapWithKeys(fn ($total, $dimension): array => [(string) $dimension => (float) $total])->all();
    }

    /** The dimensions a metric was recorded for since a moment. @return list<string> */
    public function dimensions(string $metric, CarbonImmutable $since): array
    {
        return IntelligenceSignal::query()->where('metric', $metric)->where('bucket_start', '>=', $since->toDateTimeString())
            ->whereNotNull('dimension')->distinct()->orderBy('dimension')->pluck('dimension')->map(fn ($d): string => (string) $d)->all();
    }

    /**
     * A metric aggregated per hour or day for charts: `sum` for counts,
     * `avg` or `max` for gauges. Per dimension when `$dimension` is null and
     * `$byDimension` is true.
     *
     * @return array<string, array<string, float>> dimension ('' when none) => bucket (Y-m-d H:00) => value
     */
    public function aggregate(string $metric, ?string $dimension, CarbonImmutable $since, CarbonImmutable $until, string $grain = 'hour', string $fn = 'sum', bool $byDimension = false): array
    {
        $fn = in_array($fn, ['sum', 'avg', 'max'], true) ? $fn : 'sum';
        $driver = DB::connection()->getDriverName();
        $bucket = match ($driver) {
            'pgsql' => $grain === 'day' ? "to_char(date_trunc('day', bucket_start), 'YYYY-MM-DD')" : "to_char(date_trunc('hour', bucket_start), 'YYYY-MM-DD HH24:00')",
            'mysql', 'mariadb' => $grain === 'day' ? "DATE_FORMAT(bucket_start, '%Y-%m-%d')" : "DATE_FORMAT(bucket_start, '%Y-%m-%d %H:00')",
            default => $grain === 'day' ? "strftime('%Y-%m-%d', bucket_start)" : "strftime('%Y-%m-%d %H:00', bucket_start)",
        };

        $query = IntelligenceSignal::query()
            ->where('metric', $metric)
            ->where('bucket_start', '>=', $since->toDateTimeString())
            ->where('bucket_start', '<', $until->toDateTimeString())
            ->selectRaw("{$bucket} as b, ".($byDimension ? 'dimension' : "'' as dimension").", {$fn}(value) as v")
            ->groupByRaw($byDimension ? "{$bucket}, dimension" : $bucket)
            ->orderByRaw($bucket);
        if (! $byDimension) {
            $dimension === null ? $query->whereNull('dimension') : $query->where('dimension', $dimension);
        }

        $out = [];
        foreach ($query->get() as $row) {
            $out[(string) ($row->dimension ?? '')][(string) $row->b] = round((float) $row->v, 4);
        }

        return $out;
    }

    /** Deletes samples older than the retention. */
    public function prune(CarbonImmutable $before): int
    {
        $deleted = 0;
        do {
            $batch = DB::table('intelligence_signals')->where('bucket_start', '<', $before->toDateTimeString())->limit(5000)->pluck('id');
            if ($batch->isEmpty()) {
                break;
            }
            $deleted += DB::table('intelligence_signals')->whereIn('id', $batch->all())->delete();
        } while ($batch->count() === 5000);

        return $deleted;
    }
}
