<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Carbon\CarbonImmutable;

/**
 * Application Performance: HTTP volume, server-error rate and latency by
 * route group over the window, from the request counters (sampled totals on
 * a database cache store — the description says so).
 */
final class ApplicationHealthAnalysis extends SystemAnalysis
{
    public function panels(SituationContext $context): array
    {
        return [
            'app_headline' => fn (): SituationPanel => $this->headline($context),
            'error_rate_by_group' => fn (): SituationPanel => $this->errorRateByGroup($context),
            'latency_trend' => fn (): SituationPanel => $this->latencyTrend($context),
            'requests_by_group' => fn (): SituationPanel => $this->requestsByGroup($context),
            'p95_by_group' => fn (): SituationPanel => $this->p95ByGroup($context),
        ];
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $insights = $this->incidentInsights([Metrics::COMPONENT_APPLICATION], 5);
        if (! $this->telemetryOn()) {
            return $insights;
        }
        $now = CarbonImmutable::now();
        $byGroup = $this->signals->sumByDimension(Metrics::REQUEST_TOTAL, $now->subDay());
        arsort($byGroup);
        foreach (array_slice(array_keys($byGroup), 0, 3) as $group) {
            $hourly = $this->signals->aggregate(Metrics::REQUEST_LATENCY_P95_MS, $group, $now->subDay(), $now, 'hour', 'max');
            $insight = $this->trendInsight('latency_deteriorating', array_values($hourly[''] ?? []), ['group' => $group, 'window' => '24h'], $this->source('request_counters'));
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }

        return $insights;
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('app_headline', $this->source('request_counters'), 'TELEMETRY_OFF', 3);
        }
        [$from, $to] = $this->window($context);
        $requests = (int) array_sum($this->signals->sumByDimension(Metrics::REQUEST_TOTAL, $from, $to));
        $failures = (int) array_sum($this->signals->sumByDimension(Metrics::REQUEST_5XX, $from, $to));
        $p95 = 0.0;
        foreach ($this->signals->aggregate(Metrics::REQUEST_LATENCY_P95_MS, null, $from, $to, 'day', 'max', true) as $series) {
            $p95 = max($p95, ...array_values($series) ?: [0.0]);
        }

        return SituationPanel::kpis('app_headline', $this->title('app_headline'), $this->source('request_counters'), [
            $this->kpi('requests_total', $requests),
            $this->kpi('error_rate', $requests > 0 ? round(100 * $failures / $requests, 2) : null, ['unit' => 'percent']),
            $this->kpi('p95_max', $requests > 0 ? (int) $p95 : null, ['unit' => 'ms']),
            $this->kpi('slow_requests', (int) array_sum($this->signals->sumByDimension(Metrics::REQUEST_SLOW, $from, $to))),
        ], $this->hint('app_headline'));
    }

    private function errorRateByGroup(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('error_rate_by_group', $this->source('request_counters'), 'TELEMETRY_OFF', 2);
        }
        [$from, $to, $grain] = $this->window($context);
        $totals = $this->signals->aggregate(Metrics::REQUEST_TOTAL, null, $from, $to, $grain, 'sum', true);
        $failures = $this->signals->aggregate(Metrics::REQUEST_5XX, null, $from, $to, $grain, 'sum', true);
        $volume = array_map('array_sum', $totals);
        arsort($volume);
        $rates = [];
        foreach (array_slice(array_keys($volume), 0, 5) as $group) {
            foreach ($totals[$group] as $b => $r) {
                $rates[$group][$b] = $r > 0 ? round(100 * ($failures[$group][$b] ?? 0) / $r, 2) : 0.0;
            }
        }

        return $this->lineFrom('error_rate_by_group', $this->source('request_counters'), $rates, 'percent', 2, 'route_group');
    }

    private function latencyTrend(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('latency_trend', $this->source('request_counters'), 'TELEMETRY_OFF', 2);
        }
        [$from, $to, $grain] = $this->window($context);
        $p95 = $this->signals->aggregate(Metrics::REQUEST_LATENCY_P95_MS, null, $from, $to, $grain, 'max', true);
        $volume = array_map('array_sum', $this->signals->aggregate(Metrics::REQUEST_TOTAL, null, $from, $to, $grain, 'sum', true));
        arsort($volume);
        $top = array_intersect_key($p95, array_flip(array_slice(array_keys($volume), 0, 5)));

        return $this->lineFrom('latency_trend', $this->source('request_counters'), $top, 'ms', 2, 'route_group');
    }

    private function requestsByGroup(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('requests_by_group', $this->source('request_counters'), 'TELEMETRY_OFF');
        }
        [$from, $to] = $this->window($context);
        $top = $this->topWithOther(array_map('intval', $this->signals->sumByDimension(Metrics::REQUEST_TOTAL, $from, $to)));

        return $this->chart('requests_by_group', 'hbar', $this->source('request_counters'),
            array_map(fn (string $g): array => SituationLabel::key(self::PREFIX."values.route_group.{$g}"), array_keys($top)),
            [['key' => 'requests', 'label' => SituationLabel::key(self::PREFIX.'series.requests'), 'values' => array_values($top)]]);
    }

    private function p95ByGroup(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('p95_by_group', $this->source('request_counters'), 'TELEMETRY_OFF');
        }
        [$from, $to] = $this->window($context);
        $maxes = [];
        foreach ($this->signals->aggregate(Metrics::REQUEST_LATENCY_P95_MS, null, $from, $to, 'day', 'max', true) as $group => $series) {
            $maxes[$group] = (int) max(array_values($series) ?: [0]);
        }
        arsort($maxes);
        $maxes = array_slice($maxes, 0, $context->topN, true);

        return $this->chart('p95_by_group', 'hbar', $this->source('request_counters'),
            array_map(fn (string $g): array => SituationLabel::key(self::PREFIX."values.route_group.{$g}"), array_keys($maxes)),
            [['key' => 'p95', 'label' => SituationLabel::key(self::PREFIX.'series.p95'), 'values' => array_values($maxes)]], unit: 'ms');
    }
}
