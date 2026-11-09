<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Models\IntelligenceIncident;
use App\Services\Intelligence\Analysis\TrendDetector;
use App\Services\Intelligence\Insight;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationSettings;
use Carbon\CarbonImmutable;

/**
 * Base of the technical analyses: telemetry windows, hourly series into
 * panels, incident insights. Telemetry has no organization scope — the
 * platform is one — so these ignore the context's scope and read the period
 * only, capped at TELEMETRY_MAX_DAYS so a year never means 100,000 samples.
 */
abstract class SystemAnalysis extends IntelligenceAnalysis
{
    public const TELEMETRY_MAX_DAYS = 30;

    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        protected readonly SignalRepository $signals,
        protected readonly IntelligenceSettings $intelligence,
        protected readonly ComponentHealth $health,
    ) {
        parent::__construct($rollup, $settings);
    }

    /** The telemetry window: the context period, capped. @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} from, to, grain */
    protected function window(SituationContext $context): array
    {
        $to = CarbonImmutable::now();
        $from = CarbonImmutable::parse($context->period()->from->toDateTimeString());
        if ($from->lt($to->subDays(self::TELEMETRY_MAX_DAYS))) {
            $from = $to->subDays(self::TELEMETRY_MAX_DAYS);
        }
        $grain = $from->diffInDays($to) > 3 ? 'day' : 'hour';

        return [$from, $to, $grain];
    }

    /** Whether telemetry is on; otherwise panels are TELEMETRY_OFF notices. */
    protected function telemetryOn(): bool
    {
        return $this->intelligence->telemetryEnabled();
    }

    /**
     * A line chart from hourly/daily aggregates, one series per dimension.
     *
     * @param  array<string, array<string, float>>  $byDimension  dimension => bucket => value
     */
    protected function lineFrom(string $key, string $source, array $byDimension, ?string $unit = null, int $span = 2, ?string $labelGroup = null): SituationPanel
    {
        $buckets = [];
        foreach ($byDimension as $series) {
            foreach (array_keys($series) as $b) {
                $buckets[$b] = true;
            }
        }
        ksort($buckets);
        $categories = array_map(fn (string $b): string => $b, array_keys($buckets));
        $series = [];
        foreach ($byDimension as $dimension => $values) {
            $series[] = [
                'key' => $dimension === '' ? 'all' : $dimension,
                'label' => $dimension === '' ? SituationLabel::key(self::PREFIX.'series.all') : ($labelGroup !== null ? SituationLabel::key(self::PREFIX."values.{$labelGroup}.{$dimension}") : $dimension),
                'values' => array_map(fn (string $b): float => (float) ($values[$b] ?? 0), $categories),
            ];
        }

        return $this->chart($key, 'line', $source, $categories, $series, $span, $unit);
    }

    /** Insights for the active incidents of the given components, most severe first. @return list<Insight> */
    protected function incidentInsights(array $components, int $limit = 6): array
    {
        $incidents = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->whereIn('component', $components)
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")->orderByDesc('last_seen_at')->limit($limit)->get();

        return $incidents->map(fn (IntelligenceIncident $i): Insight => new Insight(
            key: 'incident_'.$i->reference,
            severity: $i->severity,
            title: $i->title,
            titleParams: $i->evidence['params'] ?? [],
            summary: $i->impact_summary,
            summaryParams: $i->evidence['params'] ?? [],
            evidence: array_map(fn (array $row): array => ['label' => ['key' => self::PREFIX.'evidence.'.$row['label']], 'value' => $row['value']], $i->evidence['rows'] ?? []),
            recommendation: self::PREFIX.'insights.open_incident.recommendation',
            recommendationParams: ['reference' => $i->reference],
            href: route('intelligence.incidents.show', $i->id),
            source: self::PREFIX.'sources.telemetry',
        ))->all();
    }

    /** A deteriorating-trend insight for a series, or null. */
    protected function trendInsight(string $key, array $values, array $params, string $source, string $severity = 'medium', ?string $href = null): ?Insight
    {
        $trend = TrendDetector::deteriorating($values);
        if (! $trend['deteriorating']) {
            return null;
        }

        return $this->insight(
            $key,
            $severity,
            [...$params, 'first' => round((float) $trend['first'], 1), 'last' => round((float) $trend['last'], 1), 'percent' => round((float) $trend['increase_percent'], 0), 'points' => $trend['points']],
            [
                $this->evidence('window', $params['window'] ?? ''),
                $this->evidence('samples', $trend['points']),
                $this->evidence('first_value', $trend['first']),
                $this->evidence('last_value', $trend['last']),
                $this->evidence('slope_per_step', $trend['slope']),
                $this->evidence('rising_steps_share', $trend['rising_steps_share']),
            ],
            $this->change($trend['last'], $trend['first']),
            $trend['confidence'],
            $href,
            $source,
        );
    }
}
