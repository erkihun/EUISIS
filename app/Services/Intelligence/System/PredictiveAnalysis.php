<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Services\Intelligence\Analysis\TrendDetector;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Carbon\CarbonImmutable;

/**
 * Predictive Failure Monitor (brief §33): watches a fixed list of series for
 * a sustained deterioration and says "may cause degradation if the trend
 * continues" with the evidence, the window and a confidence that reflects
 * the number of points — never a guaranteed failure.
 */
final class PredictiveAnalysis extends SystemAnalysis
{
    public function panels(SituationContext $context): array
    {
        return [
            'predictive_headline' => fn (): SituationPanel => $this->headline($context),
            'trend_watch' => fn (): SituationPanel => $this->watchTable(),
        ];
    }

    public function insights(SituationContext $context, array $panels): array
    {
        if (! $this->telemetryOn()) {
            return [];
        }
        $insights = [];
        foreach ($this->watched() as $series) {
            $insight = $this->trendInsight('trend_deteriorating', $series['values'], ['series' => $series['name'], 'window' => $series['window']], $this->source('telemetry'), $series['severity']);
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }

        return $insights;
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('predictive_headline', $this->source('telemetry'), 'TELEMETRY_OFF', 3);
        }
        $watched = $this->watched();
        $deteriorating = array_filter($watched, fn (array $s): bool => TrendDetector::deteriorating($s['values'])['deteriorating']);
        $insufficient = array_filter($watched, fn (array $s): bool => count($s['values']) < TrendDetector::MIN_POINTS);

        return SituationPanel::kpis('predictive_headline', $this->title('predictive_headline'), $this->source('telemetry'), [
            $this->kpi('series_watched', count($watched)),
            $this->kpi('series_deteriorating', count($deteriorating), ['state' => $deteriorating === [] ? 'clear' : 'attention']),
            $this->kpi('series_insufficient', count($insufficient), ['state' => $insufficient === [] ? 'clear' : 'not_configured']),
        ], $this->hint('predictive_headline'));
    }

    private function watchTable(): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('trend_watch', $this->source('telemetry'), 'TELEMETRY_OFF', 3);
        }
        $rows = [];
        foreach ($this->watched() as $series) {
            $trend = TrendDetector::deteriorating($series['values']);
            $verdict = $trend['points'] < TrendDetector::MIN_POINTS ? 'insufficient' : ($trend['deteriorating'] ? 'deteriorating' : 'stable');
            $rows[] = [
                'key' => $series['key'],
                'series' => $series['name'],
                'window' => $series['window'],
                'points' => $trend['points'],
                'first_value' => $trend['first'],
                'last_value' => $trend['last'],
                'change' => $trend['increase_percent'],
                'verdict' => SituationLabel::key(self::PREFIX."values.trend_verdict.{$verdict}"),
                'confidence' => $trend['confidence'] !== null ? SituationLabel::key(self::PREFIX."confidence.{$trend['confidence']}") : '',
            ];
        }

        return SituationPanel::table('trend_watch', $this->title('trend_watch'), $this->hint('trend_watch'), $this->source('telemetry'), [
            ['key' => 'series', 'label' => SituationLabel::key(self::PREFIX.'columns.series'), 'type' => 'text'],
            ['key' => 'window', 'label' => SituationLabel::key(self::PREFIX.'columns.window'), 'type' => 'text'],
            ['key' => 'points', 'label' => SituationLabel::key(self::PREFIX.'columns.points'), 'type' => 'number'],
            ['key' => 'first_value', 'label' => SituationLabel::key(self::PREFIX.'columns.first_value'), 'type' => 'number'],
            ['key' => 'last_value', 'label' => SituationLabel::key(self::PREFIX.'columns.last_value'), 'type' => 'number'],
            ['key' => 'change', 'label' => SituationLabel::key(self::PREFIX.'columns.change'), 'type' => 'percent'],
            ['key' => 'verdict', 'label' => SituationLabel::key(self::PREFIX.'columns.verdict'), 'type' => 'label'],
            ['key' => 'confidence', 'label' => SituationLabel::key(self::PREFIX.'columns.confidence'), 'type' => 'label'],
        ], $rows, span: 3);
    }

    /**
     * The watched series, oldest point first.
     *
     * @return list<array{key: string, name: string, window: string, values: list<float>, severity: string}>
     */
    private function watched(): array
    {
        $now = CarbonImmutable::now();
        $day = $now->subDay();
        $week = $now->subDays(7);
        $hourly = fn (string $metric, ?string $dim, string $fn): array => array_values($this->signals->aggregate($metric, $dim, $day, $now, 'hour', $fn)[''] ?? []);
        $out = [
            ['key' => 'db_ping', 'name' => 'db.ping_ms (avg/h)', 'window' => '24h', 'values' => $hourly(Metrics::DB_PING_MS, null, 'avg'), 'severity' => 'high'],
            ['key' => 'db_connections', 'name' => 'db.connections_percent (max/h)', 'window' => '24h', 'values' => $hourly(Metrics::DB_CONNECTIONS_PERCENT, null, 'max'), 'severity' => 'high'],
            ['key' => 'storage_used', 'name' => 'storage used % (max/day)', 'window' => '7d', 'values' => array_map(fn (float $free): float => 100 - $free, array_values($this->signals->aggregate(Metrics::STORAGE_FREE_PERCENT, null, $week, $now, 'day', 'avg')[''] ?? [])), 'severity' => 'medium'],
        ];
        // Reverse the storage series direction: free space falling is "used" rising, which TrendDetector reads as deterioration.
        $out[2]['values'] = array_map(fn ($v): float => (float) $v, $out[2]['values']);
        foreach ($this->signals->aggregate(Metrics::QUEUE_PENDING, null, $day, $now, 'hour', 'max', true) as $queue => $series) {
            $out[] = ['key' => 'queue_'.$queue, 'name' => "queue.pending[{$queue}] (max/h)", 'window' => '24h', 'values' => array_values($series), 'severity' => 'medium'];
        }
        $volume = $this->signals->sumByDimension(Metrics::REQUEST_TOTAL, $day);
        arsort($volume);
        foreach (array_slice(array_keys($volume), 0, 3) as $group) {
            $out[] = ['key' => 'p95_'.$group, 'name' => "request.latency_p95_ms[{$group}] (max/h)", 'window' => '24h', 'values' => $hourly(Metrics::REQUEST_LATENCY_P95_MS, $group, 'max'), 'severity' => 'medium'];
        }

        return $out;
    }
}
