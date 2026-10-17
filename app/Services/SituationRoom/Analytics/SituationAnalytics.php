<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationPeriod;
use App\Services\SituationRoom\SituationSettings;
use App\Services\SituationRoom\SituationSql;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\Lang;

/**
 * One Situation Room category's panels.
 *
 * `panels()` returns a closure per panel rather than the panels themselves:
 * SituationRoomService runs each one on its own, so one failing query becomes
 * one "could not load" card instead of a blank page. Every query an analytics
 * class runs starts from a scope-confined base query; nothing is ever counted
 * first and filtered afterwards.
 */
abstract class SituationAnalytics
{
    public function __construct(
        protected readonly OrganizationRollup $rollup,
        protected readonly SituationSettings $settings,
    ) {}

    /** @return array<string, callable(): SituationPanel> */
    abstract public function panels(SituationContext $context): array;

    /**
     * Counts per roll-up row, with names, from a per-organization count.
     *
     * @param  array<string, int|float>  $byOrganization
     * @return array{categories: list<array{en: ?string, am: ?string}>, values: list<int|float>, ids: list<string>}
     */
    protected function rolledUp(SituationContext $context, array $byOrganization): array
    {
        $rollup = $this->rollup->for($context->scope);
        $totals = OrganizationRollup::sum($byOrganization, $rollup);
        $categories = [];
        $values = [];
        $ids = [];

        foreach ($rollup['groups'] as $group) {
            $categories[] = $group['own']
                ? ['en' => ($group['label']['en'] ?? '').' (own office)', 'am' => ($group['label']['am'] ?? $group['label']['en'] ?? '').' (የራሱ ጽሕፈት ቤት)']
                : $group['label'];
            $values[] = $totals[$group['id']] ?? 0;
            $ids[] = $group['id'];
        }

        if (($totals['__unplaced'] ?? 0) > 0) {
            $categories[] = ['en' => 'Not placed in the published hierarchy', 'am' => 'በጸደቀው መዋቅር ያልተቀመጠ'];
            $values[] = $totals['__unplaced'];
            $ids[] = '__unplaced';
        }

        return ['categories' => $categories, 'values' => $values, 'ids' => $ids];
    }

    /**
     * A roll-up broken down by a second dimension: one series per bucket,
     * one category per roll-up row.
     *
     * @param  array<string, array<string, int>>  $byOrganizationBucket  [organization id][bucket] => count
     * @param  list<string>  $buckets
     * @return array{categories: list<array{en: ?string, am: ?string}>, series: list<array{key: string, label: array{key: string}, values: list<int>}>}
     */
    protected function rolledUpMatrix(SituationContext $context, array $byOrganizationBucket, array $buckets, string $labelGroup): array
    {
        $series = [];
        $categories = [];

        foreach ($buckets as $bucket) {
            $perOrganization = array_map(fn (array $row): int => $row[$bucket] ?? 0, $byOrganizationBucket);
            $rolled = $this->rolledUp($context, $perOrganization);
            $categories = $rolled['categories'];
            $series[] = ['key' => $bucket, 'label' => SituationLabel::key("situationRoom.values.{$labelGroup}.{$bucket}"), 'values' => $rolled['values']];
        }

        return ['categories' => $categories, 'series' => $series];
    }

    /**
     * The n largest buckets and one "other" bucket for the rest, so a long
     * tail never turns a chart into noise. Ordered by size because this is a
     * composition (what the workforce is made of), never a comparison of
     * organizations or people.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    protected function topWithOther(array $counts, ?int $n = null): array
    {
        $n ??= $this->settings->topN();
        arsort($counts);
        $top = array_slice($counts, 0, $n, true);
        $rest = array_sum(array_slice($counts, $n, null, true));

        return $rest > 0 ? $top + ['__other' => $rest] : $top;
    }

    /**
     * Rows of a grouped count, keyed by the group column.
     *
     * @return array<string, int>
     */
    protected function countBy(QueryContract $query, string $column): array
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;

        return (clone $base)
            ->selectRaw("{$column} AS bucket, COUNT(*) AS total")
            // By the output alias: an expression would be quoted as an identifier, and a
            // parameterised one would not match its SELECT twin on PostgreSQL.
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->mapWithKeys(fn ($total, $bucket): array => [(string) $bucket => (int) $total])
            ->all();
    }

    /**
     * A per-day count bucketed to the period's granularity: day, ISO week
     * (labelled by its Monday) or month. Days with nothing are zeros, so a
     * quiet day reads as zero rather than disappearing from the line.
     *
     * @return array{categories: list<string>, values: list<int>}
     */
    protected function trend(QueryContract $query, string $column, SituationPeriod $period, bool $dateColumn = false, ?string $granularity = null): array
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $expression = SituationSql::day($column);
        $perDay = (clone $base)
            ->selectRaw("{$expression} AS day, COUNT(*) AS total")
            ->whereBetween($column, $this->bounds($period, $dateColumn))
            ->groupByRaw($expression)
            ->pluck('total', 'day')
            ->all();

        $granularity ??= $period->granularity();
        $buckets = [];
        for ($day = $period->from->startOfDay(); $day->lte($period->to); $day = $day->addDay()) {
            $buckets[$this->bucket($day, $granularity)] ??= 0;
        }
        foreach ($perDay as $day => $total) {
            $key = $this->bucket(CarbonImmutable::parse((string) $day), $granularity);
            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += (int) $total;
            }
        }

        return ['categories' => array_keys($buckets), 'values' => array_values($buckets)];
    }

    /**
     * A small count that could point to a person, shown as "fewer than k".
     *
     * @return array{value: ?int, suppressed: bool}
     */
    protected function smallCell(int $value): array
    {
        $k = $this->settings->smallCellThreshold();

        return $value > 0 && $value < $k ? ['value' => null, 'suppressed' => true] : ['value' => $value, 'suppressed' => false];
    }

    /**
     * Apply the small-cell rule to every value of a series.
     *
     * @param  list<int|float>  $values
     * @return list<int|float|null>
     */
    protected function suppressSeries(array $values): array
    {
        return array_map(fn ($v): int|float|null => is_int($v) && $this->smallCell($v)['suppressed'] ? null : $v, $values);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function kpi(string $key, int|float|null $value, array $extra = []): array
    {
        // A definition only where the figure needs one (lang situation_room.kpiHint).
        $definition = Lang::has('situation_room.kpiHint.'.$key, 'en') ? 'situationRoom.kpiHint.'.$key : null;

        return ['key' => $key, 'label' => 'situationRoom.kpi.'.$key, 'definition' => $definition, 'value' => $value] + $extra;
    }

    protected function percent(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round(100 * $part / $whole, 1) : null;
    }

    /** Labels for enum-like values: situationRoom.values.<group>.<value>. */
    protected function valueLabels(string $group, array $values): array
    {
        return array_map(fn ($value): array => SituationLabel::key("situationRoom.values.{$group}.".($value === '' ? 'unknown' : $value)), $values);
    }

    protected function inPeriod(QueryContract $query, string $column, SituationPeriod $period, bool $dateColumn = false): QueryContract
    {
        return $query->whereBetween($column, $this->bounds($period, $dateColumn));
    }

    /**
     * Inclusive bounds of a period: full timestamps for a timestamp column,
     * plain dates for a date column (a date compared with "Y-m-d 00:00:00"
     * as text would drop the first day on SQLite).
     *
     * @return array{0: string, 1: string}
     */
    protected function bounds(SituationPeriod $period, bool $dateColumn = false): array
    {
        return $dateColumn
            ? [$period->from->toDateString(), $period->to->toDateString()]
            : [$period->from->toDateTimeString(), $period->to->toDateTimeString()];
    }

    protected function title(string $key): string
    {
        return "situationRoom.panels.{$key}.title";
    }

    protected function hint(string $key): string
    {
        return "situationRoom.panels.{$key}.description";
    }

    /** Shortcut for a chart whose title and description follow the panel key. */
    protected function chart(string $key, string $type, string $source, array $categories, array $series, int $span = 1, ?string $unit = null, ?string $href = null, ?string $palette = null): SituationPanel
    {
        return SituationPanel::chart($key, $type, $this->title($key), $this->hint($key), $source, $categories, $series, $href, $span, $unit, $palette);
    }

    protected function notice(string $key, string $source, string $reason, int $span = 1): SituationPanel
    {
        return SituationPanel::notice($key, $this->title($key), $this->hint($key), $source, $reason, $span);
    }

    private function bucket(CarbonImmutable $day, string $granularity): string
    {
        return match ($granularity) {
            'week' => $day->startOfWeek()->toDateString(),
            'month' => $day->format('Y-m'),
            default => $day->toDateString(),
        };
    }
}
