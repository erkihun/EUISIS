<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Models\IntelligenceIncident;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;

/**
 * System Health: every component's state from its freshest samples and
 * active incidents, the incident headline, and the platform's error rate and
 * failures over the window. Unknown stays unknown.
 */
final class SystemHealthAnalysis extends SystemAnalysis
{
    public function panels(SituationContext $context): array
    {
        return [
            'system_headline' => fn (): SituationPanel => $this->headline($context),
            'component_states' => fn (): SituationPanel => $this->componentStates(),
            'error_rate_trend' => fn (): SituationPanel => $this->errorRateTrend($context),
            'failures_by_group' => fn (): SituationPanel => $this->failuresByGroup($context),
        ];
    }

    public function insights(SituationContext $context, array $panels): array
    {
        return $this->incidentInsights(Metrics::components(), 8);
    }

    /** The compact strip the Overview composes. */
    public function headline(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('system_headline', $this->source('telemetry'), 'TELEMETRY_OFF', 3);
        }
        $states = $this->health->states();
        $summary = $this->health->summary($states);
        $active = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity');
        $state = fn (int $n, string $tone): string => $n > 0 ? $tone : 'clear';

        return SituationPanel::kpis('system_headline', $this->title('system_headline'), $this->source('telemetry'), [
            $this->kpi('incidents_critical', (int) ($active['critical'] ?? 0), ['state' => $state((int) ($active['critical'] ?? 0), 'attention'), 'href' => route('intelligence.incidents.index', ['severity' => 'critical'])]),
            $this->kpi('incidents_high', (int) ($active['high'] ?? 0), ['state' => $state((int) ($active['high'] ?? 0), 'attention'), 'href' => route('intelligence.incidents.index', ['severity' => 'high'])]),
            $this->kpi('incidents_medium', (int) ($active['medium'] ?? 0), ['href' => route('intelligence.incidents.index', ['severity' => 'medium'])]),
            $this->kpi('components_unhealthy', $summary['unhealthy'], ['state' => $state($summary['unhealthy'], 'attention')]),
            $this->kpi('components_degraded', $summary['degraded'], ['state' => $state($summary['degraded'], 'attention')]),
            $this->kpi('components_unknown', $summary['unknown'], ['state' => $summary['unknown'] > 0 ? 'not_configured' : 'clear']),
        ], $this->hint('system_headline'));
    }

    private function componentStates(): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('component_states', $this->source('telemetry'), 'TELEMETRY_OFF', 3);
        }
        $rows = [];
        foreach ($this->health->states() as $component => $s) {
            $detail = [];
            foreach ($s['detail'] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $detail[] = str_replace('_', ' ', $k).': '.(is_float($v) ? round($v, 1) : $v);
                }
            }
            // Column values sit on the row itself: the panel table reads row[column.key].
            $rows[] = [
                'key' => $component,
                'component' => SituationLabel::key(self::PREFIX."components.{$component}"),
                'state' => SituationLabel::key(self::PREFIX."health.{$s['state']}"),
                'incidents' => $s['incidents'],
                'detail' => $detail === [] ? ($s['reason'] !== null ? SituationLabel::key(self::PREFIX."reasons.{$s['reason']}.title") : '') : implode(' · ', $detail),
                'at' => $s['at'],
                'href' => $s['incidents'] > 0 ? route('intelligence.incidents.index', ['component' => $component]) : null,
            ];
        }

        return SituationPanel::table('component_states', $this->title('component_states'), $this->hint('component_states'), $this->source('telemetry'), [
            ['key' => 'component', 'label' => SituationLabel::key(self::PREFIX.'columns.component'), 'type' => 'label'],
            ['key' => 'state', 'label' => SituationLabel::key(self::PREFIX.'columns.state'), 'type' => 'label'],
            ['key' => 'incidents', 'label' => SituationLabel::key(self::PREFIX.'columns.active_incidents'), 'type' => 'number'],
            ['key' => 'detail', 'label' => SituationLabel::key(self::PREFIX.'columns.detail'), 'type' => 'label'],
            ['key' => 'at', 'label' => SituationLabel::key(self::PREFIX.'columns.sampled_at'), 'type' => 'date'],
        ], $rows, span: 3);
    }

    private function errorRateTrend(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('error_rate_trend', $this->source('request_counters'), 'TELEMETRY_OFF', 2);
        }
        [$from, $to, $grain] = $this->window($context);
        $total = $this->signals->aggregate(Metrics::REQUEST_TOTAL, null, $from, $to, $grain, 'sum', true);
        $failed = $this->signals->aggregate(Metrics::REQUEST_5XX, null, $from, $to, $grain, 'sum', true);
        // Weighted across groups: failures over requests per bucket.
        $requests = [];
        $failures = [];
        foreach ($total as $series) {
            foreach ($series as $b => $v) {
                $requests[$b] = ($requests[$b] ?? 0) + $v;
            }
        }
        foreach ($failed as $series) {
            foreach ($series as $b => $v) {
                $failures[$b] = ($failures[$b] ?? 0) + $v;
            }
        }
        $rate = [];
        foreach ($requests as $b => $r) {
            $rate[$b] = $r > 0 ? round(100 * ($failures[$b] ?? 0) / $r, 2) : 0.0;
        }

        return $this->lineFrom('error_rate_trend', $this->source('request_counters'), ['' => $rate], 'percent');
    }

    private function failuresByGroup(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('failures_by_group', $this->source('request_counters'), 'TELEMETRY_OFF');
        }
        [$from, $to] = $this->window($context);
        $top = $this->topWithOther(array_map('intval', $this->signals->sumByDimension(Metrics::REQUEST_5XX, $from, $to)));

        return $this->chart('failures_by_group', 'hbar', $this->source('request_counters'),
            array_map(fn (string $g): array => SituationLabel::key(self::PREFIX."values.route_group.{$g}"), array_keys($top)),
            [['key' => 'failures', 'label' => SituationLabel::key(self::PREFIX.'series.failures'), 'values' => array_values($top)]]);
    }
}
