<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationPanel;
use Carbon\CarbonImmutable;

/**
 * Database Health (brief §37–38): probe time, connection usage, long-running
 * queries, waiting locks and deadlocks where the database exposes them.
 * Statements and parameters are never read or shown; a figure the driver
 * does not expose stays UNKNOWN.
 */
final class DatabaseHealthAnalysis extends SystemAnalysis
{
    public function panels(SituationContext $context): array
    {
        return [
            'db_headline' => fn (): SituationPanel => $this->headline($context),
            'db_ping_trend' => fn (): SituationPanel => $this->gaugeTrend($context,'db_ping_trend', Metrics::DB_PING_MS, 'avg', 'ms'),
            'db_connections_trend' => fn (): SituationPanel => $this->gaugeTrend($context,'db_connections_trend', Metrics::DB_CONNECTIONS_PERCENT, 'max', 'percent'),
            'db_long_queries_trend' => fn (): SituationPanel => $this->gaugeTrend($context,'db_long_queries_trend', Metrics::DB_LONG_QUERIES, 'max', null),
        ];
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $insights = $this->incidentInsights([Metrics::COMPONENT_DATABASE], 5);
        if (! $this->telemetryOn()) {
            return $insights;
        }
        $now = CarbonImmutable::now();
        foreach ([['db_ping_deteriorating', Metrics::DB_PING_MS, 'avg'], ['db_connections_deteriorating', Metrics::DB_CONNECTIONS_PERCENT, 'max']] as [$key, $metric, $fn]) {
            $hourly = $this->signals->aggregate($metric, null, $now->subDay(), $now, 'hour', $fn);
            $insight = $this->trendInsight($key, array_values($hourly[''] ?? []), ['window' => '24h'], $this->source('telemetry'), 'high');
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }

        return $insights;
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('db_headline', $this->source('telemetry'), 'TELEMETRY_OFF', 3);
        }
        [$from, $to] = $this->window($context);
        $fresh = CarbonImmutable::now()->subMinutes(ComponentHealth::FRESH_MINUTES);
        $latest = fn (string $metric) => $this->signals->latest($metric, null, $fresh)['value'] ?? null;
        $deadlocks = $this->signals->series(Metrics::DB_DEADLOCKS_TOTAL, null, $from, $to);
        $deadlockDelta = count($deadlocks) >= 2 ? max(0, (int) end($deadlocks)['value'] - (int) $deadlocks[0]['value']) : null;

        return SituationPanel::kpis('db_headline', $this->title('db_headline'), $this->source('telemetry'), [
            $this->kpi('db_ping', $latest(Metrics::DB_PING_MS), ['unit' => 'ms']),
            $this->kpi('db_connections_percent', $latest(Metrics::DB_CONNECTIONS_PERCENT), ['unit' => 'percent']),
            $this->kpi('db_long_queries', $latest(Metrics::DB_LONG_QUERIES)),
            $this->kpi('db_waiting_locks', $latest(Metrics::DB_WAITING_LOCKS)),
            $this->kpi('db_deadlocks_delta', $deadlockDelta),
        ], $this->hint('db_headline'));
    }

    private function gaugeTrend(SituationContext $context, string $key, string $metric, string $fn, ?string $unit): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice($key, $this->source('telemetry'), 'TELEMETRY_OFF', 2);
        }
        [$from, $to, $grain] = $this->window($context);
        $series = $this->signals->aggregate($metric, null, $from, $to, $grain, $fn);
        if ($series === []) {
            return $this->notice($key, $this->source('telemetry'), 'UNKNOWN', 2);
        }

        return $this->lineFrom($key, $this->source('telemetry'), $series, $unit);
    }
}
