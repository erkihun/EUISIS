<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\SituationRoom\SituationLabel;
use App\Support\Database\SqlDateBucket;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * What every business analysis needs besides the Situation Room helpers:
 * a date series bucketed with SqlDateBucket (the Situation Room's own
 * trend() has no MySQL branch), the complete-month windows a baseline or a
 * forecast is built on, label shortcuts for the Intelligence dictionary and
 * a severity scale for "how many records are affected" insights.
 */
trait BusinessQueries
{
    /**
     * Counts per day / ISO week / month between two instants, with every
     * empty bucket present as zero so a quiet week reads as zero rather than
     * disappearing from the line.
     *
     * @return array{categories: list<string>, values: list<int>}
     */
    protected function series(QueryContract $query, string $column, CarbonImmutable $from, CarbonImmutable $to, string $unit, bool $dateColumn = false): array
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $expression = SqlDateBucket::expression($column, $unit);
        $bounds = $dateColumn ? [$from->toDateString(), $to->toDateString()] : [$from->toDateTimeString(), $to->toDateTimeString()];
        $counted = (clone $base)
            ->selectRaw("{$expression} AS bucket, COUNT(*) AS total")
            ->whereBetween($column, $bounds)
            // By the output alias: an expression would be quoted as an identifier on PostgreSQL.
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();

        $buckets = [];
        for ($cursor = $this->bucketStart($from, $unit); $cursor->lte($to); $cursor = $this->nextBucket($cursor, $unit)) {
            $buckets[$this->bucketKey($cursor, $unit)] = 0;
        }
        foreach ($counted as $bucket => $total) {
            $key = $this->bucketKey(CarbonImmutable::parse((string) $bucket, $from->getTimezone()), $unit);
            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += (int) $total;
            }
        }

        return ['categories' => array_keys($buckets), 'values' => array_values($buckets)];
    }

    /**
     * The last `$count` COMPLETE months before the current one, oldest first:
     * a partial month would bias a baseline or a forecast downwards.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, months: list<string>}
     */
    protected function completeMonths(int $count): array
    {
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $to = $now->startOfMonth()->subMicrosecond();
        $from = $now->startOfMonth()->subMonthsNoOverflow($count);
        $months = [];
        for ($cursor = $from; $cursor->lte($to); $cursor = $cursor->addMonthNoOverflow()) {
            $months[] = $cursor->format('Y-m');
        }

        return ['from' => $from, 'to' => $to, 'months' => $months];
    }

    /** A table column of the Intelligence dictionary. @return array{key: string, label: array{key: string}, type: string} */
    protected function column(string $key, string $type = 'number', ?string $labelKey = null): array
    {
        return ['key' => $key, 'label' => SituationLabel::key(IntelligenceAnalysis::PREFIX.'columns.'.($labelKey ?? $key)), 'type' => $type];
    }

    /** A value label of the Intelligence dictionary (intelligence.values.<group>.<value>). @return array{key: string} */
    protected function value(string $group, string $value): array
    {
        return SituationLabel::key(IntelligenceAnalysis::PREFIX."values.{$group}.{$value}");
    }

    /**
     * An id column as text, for COALESCE with a '__none' marker: uuid on
     * PostgreSQL, char(36) on MySQL/MariaDB (which has no VARCHAR in CAST),
     * text on SQLite.
     */
    protected function idText(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "CAST({$column} AS VARCHAR(64))",
            'mysql', 'mariadb' => "CAST({$column} AS CHAR(64))",
            default => "CAST({$column} AS TEXT)",
        };
    }

    /** The first existing route among candidates, so a drill-down link never points at a page that does not exist. */
    protected function link(string ...$routeNames): ?string
    {
        foreach ($routeNames as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }

    /** How serious "this many records are affected" is: a handful is low, a department is medium, more is high. */
    protected function severityByCount(int $count): string
    {
        return match (true) {
            $count >= 50 => 'high',
            $count >= 10 => 'medium',
            default => 'low',
        };
    }

    private function bucketStart(CarbonImmutable $day, string $unit): CarbonImmutable
    {
        return match ($unit) {
            SqlDateBucket::WEEK => $day->startOfWeek(),
            SqlDateBucket::MONTH => $day->startOfMonth(),
            default => $day->startOfDay(),
        };
    }

    private function nextBucket(CarbonImmutable $cursor, string $unit): CarbonImmutable
    {
        return match ($unit) {
            SqlDateBucket::WEEK => $cursor->addWeek(),
            SqlDateBucket::MONTH => $cursor->addMonthNoOverflow(),
            default => $cursor->addDay(),
        };
    }

    private function bucketKey(CarbonImmutable $day, string $unit): string
    {
        return match ($unit) {
            SqlDateBucket::WEEK => $day->startOfWeek()->toDateString(),
            SqlDateBucket::MONTH => $day->format('Y-m'),
            default => $day->toDateString(),
        };
    }
}
