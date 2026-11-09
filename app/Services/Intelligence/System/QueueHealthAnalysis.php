<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationPanel;
use Carbon\CarbonImmutable;

/**
 * Redis & Queue Health (brief §39–40): pending and reserved jobs per queue,
 * the oldest waiting job, failed jobs, cache and Redis availability and
 * probe time. Redis figures appear only where Redis is configured.
 */
final class QueueHealthAnalysis extends SystemAnalysis
{
    public function panels(SituationContext $context): array
    {
        return [
            'queue_headline' => fn (): SituationPanel => $this->headline($context),
            'cache_headline' => fn (): SituationPanel => $this->cacheHeadline(),
            'queue_pending_trend' => fn (): SituationPanel => $this->pendingTrend($context),
            'queue_failed_trend' => fn (): SituationPanel => $this->failedTrend($context),
        ];
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $insights = $this->incidentInsights([Metrics::COMPONENT_QUEUE, Metrics::COMPONENT_CACHE, Metrics::COMPONENT_REDIS], 5);
        if (! $this->telemetryOn()) {
            return $insights;
        }
        $now = CarbonImmutable::now();
        foreach ($this->signals->aggregate(Metrics::QUEUE_PENDING, null, $now->subDay(), $now, 'hour', 'max', true) as $queue => $hourly) {
            $insight = $this->trendInsight('queue_growing', array_values($hourly), ['queue' => $queue, 'window' => '24h'], $this->source('queue'));
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }

        return $insights;
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('queue_headline', $this->source('queue'), 'TELEMETRY_OFF', 3);
        }
        [$from, $to] = $this->window($context);
        $fresh = CarbonImmutable::now()->subMinutes(ComponentHealth::FRESH_MINUTES);
        $queues = $this->signals->dimensions(Metrics::QUEUE_PENDING, $fresh);
        $pending = 0;
        $oldest = 0.0;
        $items = [];
        foreach ($queues as $queue) {
            $p = (int) ($this->signals->latest(Metrics::QUEUE_PENDING, $queue, $fresh)['value'] ?? 0);
            $pending += $p;
            $oldest = max($oldest, (float) ($this->signals->latest(Metrics::QUEUE_OLDEST_AGE_SECONDS, $queue, $fresh)['value'] ?? 0));
        }
        $items[] = $this->kpi('queue_pending', $queues === [] ? null : $pending);
        $items[] = $this->kpi('queue_oldest_minutes', $queues === [] ? null : round($oldest / 60, 1));
        $items[] = $this->kpi('queue_failed_window', (int) $this->signals->sum(Metrics::QUEUE_FAILED_5M, null, $from, $to));
        $items[] = $this->kpi('queue_failed_total', $this->signals->latest(Metrics::QUEUE_FAILED_TOTAL, null, $fresh)['value'] ?? null);
        foreach ($queues as $queue) {
            $items[] = ['key' => 'queue_'.$queue, 'label' => 'queue: '.$queue, 'definition' => null, 'value' => (int) ($this->signals->latest(Metrics::QUEUE_PENDING, $queue, $fresh)['value'] ?? 0)];
        }

        return SituationPanel::kpis('queue_headline', $this->title('queue_headline'), $this->source('queue'), $items, $this->hint('queue_headline'));
    }

    private function cacheHeadline(): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('cache_headline', $this->source('telemetry'), 'TELEMETRY_OFF', 3);
        }
        $fresh = CarbonImmutable::now()->subMinutes(ComponentHealth::FRESH_MINUTES);
        $latest = fn (string $metric) => $this->signals->latest($metric, null, $fresh)['value'] ?? null;
        $items = [
            $this->kpi('cache_state', $latest(Metrics::CACHE_AVAILABLE), ['state' => $latest(Metrics::CACHE_AVAILABLE) === null ? 'not_configured' : ($latest(Metrics::CACHE_AVAILABLE) >= 1 ? 'clear' : 'attention')]),
            $this->kpi('cache_ping', $latest(Metrics::CACHE_PING_MS), ['unit' => 'ms']),
        ];
        if ($latest(Metrics::REDIS_AVAILABLE) !== null) {
            $items[] = $this->kpi('redis_state', $latest(Metrics::REDIS_AVAILABLE), ['state' => $latest(Metrics::REDIS_AVAILABLE) >= 1 ? 'clear' : 'attention']);
            $items[] = $this->kpi('redis_ping', $latest(Metrics::REDIS_PING_MS), ['unit' => 'ms']);
        }

        return SituationPanel::kpis('cache_headline', $this->title('cache_headline'), $this->source('telemetry'), $items, $this->hint('cache_headline'));
    }

    private function pendingTrend(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('queue_pending_trend', $this->source('queue'), 'TELEMETRY_OFF', 2);
        }
        [$from, $to, $grain] = $this->window($context);
        $series = $this->signals->aggregate(Metrics::QUEUE_PENDING, null, $from, $to, $grain, 'max', true);

        return $series === [] ? $this->notice('queue_pending_trend', $this->source('queue'), 'UNKNOWN', 2) : $this->lineFrom('queue_pending_trend', $this->source('queue'), $series, null, 2);
    }

    private function failedTrend(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('queue_failed_trend', $this->source('queue'), 'TELEMETRY_OFF', 2);
        }
        [$from, $to, $grain] = $this->window($context);
        $series = $this->signals->aggregate(Metrics::QUEUE_FAILED_5M, null, $from, $to, $grain, 'sum');

        return $series === [] ? $this->notice('queue_failed_trend', $this->source('queue'), 'UNKNOWN', 2) : $this->lineFrom('queue_failed_trend', $this->source('queue'), $series, null, 2);
    }
}
